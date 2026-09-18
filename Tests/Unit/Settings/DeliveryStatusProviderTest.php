<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Settings;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Delivery\Email\MailConfigurationInspector;
use Priebera\ContextReporter\Settings\DeliveryStatusProvider;
use Priebera\ContextReporter\Settings\SecretState;
use Priebera\ContextReporter\Settings\StatusMessage;
use Priebera\ContextReporter\Tests\Unit\Fixtures\SettingsProviderStub;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class DeliveryStatusProviderTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = [
            'transport' => 'smtp',
            'transport_smtp_server' => 'smtp.example.com:587',
            'transport_smtp_username' => 'mailer-user',
            'transport_smtp_password' => 'mailer-password',
            'defaultMailFromAddress' => 'typo3@example.com',
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['MAIL']);
        putenv('IS_DDEV_PROJECT');
        parent::tearDown();
    }

    #[Test]
    public function incompleteEmailConfigurationIsExplained(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] = '';

        $status = $this->createProvider(['email' => ['enabled' => '0', 'recipients' => '']])->getEmailStatus();

        self::assertFalse($status->enabled);
        self::assertSame([], $status->recipients);
        self::assertFalse($status->isReady());
        self::assertFalse($status->canSendTest());
        self::assertSame(['email.disabled', 'email.noRecipients', 'email.defaultSender'], $this->keys($status->messages));
        self::assertStringStartsWith('no-reply@', $status->messages[2]->arguments[0]);
    }

    #[Test]
    public function completeEmailConfigurationIsReady(): void
    {
        $status = $this->createProvider(['email' => [
            'enabled' => '1',
            'recipients' => 'support@example.com, dev@example.com',
            'senderAddress' => 'portal@example.com',
            'senderName' => 'Portal',
        ]])->getEmailStatus();

        self::assertTrue($status->isReady());
        self::assertTrue($status->canSendTest());
        self::assertSame(['support@example.com', 'dev@example.com'], $status->recipients);
        self::assertSame('portal@example.com', $status->senderAddress);
        self::assertSame('Portal', $status->senderName);
        self::assertFalse($status->senderFromTypo3);
        self::assertSame([], $status->messages);
        self::assertSame('smtp', $status->transport->type);
        $exported = var_export($status, true);
        self::assertStringNotContainsString('mailer-user', $exported);
        self::assertStringNotContainsString('mailer-password', $exported);
    }

    #[Test]
    public function transportsThatDoNotSendAreReported(): void
    {
        $configuration = ['email' => ['enabled' => '1', 'recipients' => 'support@example.com']];

        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = ['transport' => 'null', 'defaultMailFromAddress' => 'typo3@example.com'];
        $null = $this->createProvider($configuration)->getEmailStatus();
        self::assertFalse($null->isReady());
        self::assertSame(['email.nullTransport'], $this->keys($null->messages));
        self::assertSame(StatusMessage::DANGER, $null->messages[0]->severity);

        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = ['transport' => 'smtp', 'transport_smtp_server' => 'localhost:1025', 'transport_spool_type' => 'file', 'defaultMailFromAddress' => 'typo3@example.com'];
        $captured = $this->createProvider($configuration)->getEmailStatus();
        self::assertTrue($captured->isReady(), 'Warnings do not block the delivery');
        self::assertSame(['email.spool', 'email.localCatcher'], $this->keys($captured->messages));

        putenv('IS_DDEV_PROJECT=true');
        self::assertSame(['email.spool', 'email.ddev'], $this->keys($this->createProvider($configuration)->getEmailStatus()->messages));

        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = ['transport' => 'mbox', 'transport_mbox_file' => '/tmp/mbox', 'defaultMailFromAddress' => 'typo3@example.com'];
        self::assertSame(['email.mbox'], $this->keys($this->createProvider($configuration)->getEmailStatus()->messages));
    }

    #[Test]
    public function invalidSenderOfTheSystemConfigurationIsReported(): void
    {
        $status = $this->createProvider(['email' => ['enabled' => '1', 'recipients' => 'support@example.com', 'senderAddress' => 'nobody']])->getEmailStatus();

        self::assertSame(['email.senderInvalid'], $this->keys($status->messages));
        self::assertTrue($status->senderFromTypo3);
        self::assertSame('typo3@example.com', $status->senderAddress);
    }

    #[Test]
    public function webhookSecretsAreOnlyDescribedByTheirState(): void
    {
        $status = $this->createProvider(
            ['webhook' => [
                'enabled' => '1',
                'url' => '%env(CR_WEBHOOK_URL)%',
                'secret' => '%env(CR_WEBHOOK_SECRET)%',
                'authHeaderName' => 'X-Api-Key',
                'authHeaderValue' => 'plain-api-key',
            ]],
            ['CR_WEBHOOK_URL' => 'https://hooks.example.com/hook/s3cr3t'],
        )->getWebhookStatus();

        self::assertSame(SecretState::Environment, $status->endpoint);
        self::assertSame('CR_WEBHOOK_URL', $status->endpointVariable);
        self::assertSame('', $status->endpointTarget);
        self::assertSame(SecretState::EnvironmentUnavailable, $status->signature);
        self::assertSame('CR_WEBHOOK_SECRET', $status->signatureVariable);
        self::assertSame('X-Api-Key', $status->authHeaderName);
        self::assertSame(SecretState::Configured, $status->authHeaderValue);
        self::assertSame(['webhook.secretEnvironment'], $this->keys($status->messages));
        self::assertSame(['CR_WEBHOOK_SECRET'], $status->messages[0]->arguments);
        self::assertFalse($status->isReady());
        self::assertTrue($status->canSendTest());

        $exported = var_export($status, true);
        foreach (['hooks.example.com', 's3cr3t', 'plain-api-key'] as $secret) {
            self::assertStringNotContainsString($secret, $exported);
        }
    }

    #[Test]
    public function plainEndpointIsShownAsSchemeAndHost(): void
    {
        $status = $this->createProvider(['webhook' => [
            'enabled' => '1',
            'url' => 'https://hooks.example.com:8443/hook/token?key=1',
            'secret' => 'hmac-secret',
        ]])->getWebhookStatus();

        self::assertSame(SecretState::Configured, $status->endpoint);
        self::assertSame('https://hooks.example.com:8443', $status->endpointTarget);
        self::assertTrue($status->endpointHasPath);
        self::assertSame(SecretState::Configured, $status->signature);
        self::assertSame(SecretState::NotConfigured, $status->authHeaderValue);
        self::assertSame('', $status->authHeaderName);
        self::assertTrue($status->isReady());
        self::assertSame([], $status->messages);
        self::assertStringNotContainsString('token', var_export($status, true));
        self::assertStringNotContainsString('hmac-secret', var_export($status, true));
    }

    #[Test]
    public function incompleteWebhookConfigurationIsExplained(): void
    {
        $disabled = $this->createProvider([])->getWebhookStatus();
        self::assertSame(['webhook.disabled', 'webhook.noEndpoint'], $this->keys($disabled->messages));
        self::assertFalse($disabled->canSendTest());

        $invalid = $this->createProvider(['webhook' => ['enabled' => '1', 'url' => 'http://hooks.example.com/', 'authHeaderValue' => "Bearer a\nb"]])->getWebhookStatus();
        self::assertSame(SecretState::Invalid, $invalid->endpoint);
        self::assertSame(SecretState::Invalid, $invalid->authHeaderValue);
        self::assertSame(['webhook.endpointInvalid', 'webhook.authInvalid', 'webhook.unsigned'], $this->keys($invalid->messages));

        $insecure = $this->createProvider(['webhook' => ['enabled' => '1', 'url' => 'http://receiver.local/', 'allowInsecureHttp' => '1', 'secret' => 'x']])->getWebhookStatus();
        self::assertTrue($insecure->isReady());
        self::assertSame(['webhook.insecure'], $this->keys($insecure->messages));

        $missing = $this->createProvider(['webhook' => ['enabled' => '1', 'url' => '%env(CR_MISSING)%', 'authHeaderValue' => '%env(CR_TOKEN)%']])->getWebhookStatus();
        self::assertSame(SecretState::EnvironmentUnavailable, $missing->endpoint);
        self::assertSame(['webhook.endpointEnvironment', 'webhook.authEnvironment', 'webhook.unsigned'], $this->keys($missing->messages));
        self::assertSame(['CR_MISSING'], $missing->messages[0]->arguments);
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, string> $environment
     */
    private function createProvider(array $configuration, array $environment = []): DeliveryStatusProvider
    {
        return new DeliveryStatusProvider(new SettingsProviderStub($configuration, $environment), new MailConfigurationInspector());
    }

    /**
     * @param list<StatusMessage> $messages
     * @return list<string>
     */
    private function keys(array $messages): array
    {
        return array_map(static fn(StatusMessage $message): string => $message->key, $messages);
    }
}
