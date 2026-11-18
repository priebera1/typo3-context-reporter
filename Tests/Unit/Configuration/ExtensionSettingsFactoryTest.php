<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\ExtensionSettingsFactory;
use Priebera\ContextReporter\Configuration\HubIntake;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ExtensionSettingsFactoryTest extends UnitTestCase
{
    #[Test]
    public function missingConfigurationFallsBackToSafeDefaults(): void
    {
        $settings = $this->createFactory()->fromArray([], 'My Site', 'Production');

        self::assertTrue($settings->enabled);
        self::assertSame('My Site', $settings->projectName);
        self::assertSame('Production', $settings->environment);
        self::assertSame('', $settings->projectIdentifier);
        self::assertSame(5120 * 1024, $settings->maxScreenshotBytes);
        self::assertSame(20, $settings->maxReportsPerUserPerHour);
        self::assertSame(0, $settings->retentionDays, 'Reports are kept forever unless configured otherwise');
        self::assertTrue($settings->includeBrowserDetails);
        self::assertFalse($settings->includeRecentBackendErrors);

        self::assertTrue($settings->reporterPrivacy->includeUid);
        self::assertTrue($settings->reporterPrivacy->includeUsername);
        self::assertFalse($settings->reporterPrivacy->includeRealName);
        self::assertFalse($settings->reporterPrivacy->includeEmail);
        self::assertFalse($settings->reporterPrivacy->includeGroups);

        self::assertFalse($settings->email->isUsable());
        self::assertFalse($settings->webhook->isUsable());
        self::assertFalse($settings->hub->isUsable());
    }

    #[Test]
    public function configuredValuesAreApplied(): void
    {
        $settings = $this->createFactory()->fromArray([
            'general' => [
                'enabled' => '0',
                'projectName' => ' Agency Portal ',
                'projectIdentifier' => 'agency-portal',
                'environment' => 'Staging',
            ],
            'reporting' => [
                'maxScreenshotSizeKb' => '2048',
                'maxReportsPerUserPerHour' => '0',
                'retentionDays' => '90',
            ],
            'privacy' => [
                'reporterUid' => '0',
                'reporterUsername' => '0',
                'reporterRealName' => '1',
                'reporterEmail' => '1',
                'reporterGroups' => '1',
                'browserDetails' => '0',
                'recentBackendErrors' => '1',
            ],
        ], 'Fallback', 'Production');

        self::assertFalse($settings->enabled);
        self::assertSame('Agency Portal', $settings->projectName);
        self::assertSame('agency-portal', $settings->projectIdentifier);
        self::assertSame('Staging', $settings->environment);
        self::assertSame(2048 * 1024, $settings->maxScreenshotBytes);
        self::assertSame(0, $settings->maxReportsPerUserPerHour);
        self::assertSame(90, $settings->retentionDays);
        self::assertFalse($settings->includeBrowserDetails);
        self::assertTrue($settings->includeRecentBackendErrors);
        self::assertFalse($settings->reporterPrivacy->includeUid);
        self::assertFalse($settings->reporterPrivacy->includeUsername);
        self::assertTrue($settings->reporterPrivacy->includeRealName);
        self::assertTrue($settings->reporterPrivacy->includeEmail);
        self::assertTrue($settings->reporterPrivacy->includeGroups);
    }

    #[Test]
    public function screenshotSizeIsClampedToASaneRange(): void
    {
        $factory = $this->createFactory();

        self::assertSame(100 * 1024, $factory->fromArray(['reporting' => ['maxScreenshotSizeKb' => '1']], '', '')->maxScreenshotBytes);
        self::assertSame(15360 * 1024, $factory->fromArray(['reporting' => ['maxScreenshotSizeKb' => '999999']], '', '')->maxScreenshotBytes);
    }

    #[Test]
    public function projectValuesAreSingleLine(): void
    {
        $settings = $this->createFactory()->fromArray([
            'general' => ['projectName' => "Portal\r\nBcc: x@example.com", 'projectIdentifier' => "id\nx", 'environment' => "Stage\tA\nB"],
        ], 'Site', 'Production');

        self::assertSame('Portal Bcc: x@example.com', $settings->projectName);
        self::assertSame('id x', $settings->projectIdentifier);
        self::assertSame('Stage A B', $settings->environment);
    }

    #[Test]
    public function retentionIsZeroOrAPositiveNumberOfDays(): void
    {
        $factory = $this->createFactory();

        self::assertSame(365, $factory->fromArray(['reporting' => ['retentionDays' => 365]], '', '')->retentionDays);
        self::assertSame(0, $factory->fromArray(['reporting' => ['retentionDays' => '-5']], '', '')->retentionDays);
        self::assertSame(0, $factory->fromArray(['reporting' => ['retentionDays' => 'forever']], '', '')->retentionDays);
        self::assertSame(3650, $factory->fromArray(['reporting' => ['retentionDays' => 99999]], '', '')->retentionDays);
    }

    #[Test]
    public function typedValuesFromTheSettingsModuleAreAccepted(): void
    {
        $settings = $this->createFactory()->fromArray([
            'general' => ['enabled' => false],
            'reporting' => ['maxReportsPerUserPerHour' => 5],
            'email' => ['enabled' => true, 'recipients' => 'support@example.com', 'attachJson' => false],
        ], '', '');

        self::assertFalse($settings->enabled);
        self::assertSame(5, $settings->maxReportsPerUserPerHour);
        self::assertTrue($settings->email->isUsable());
        self::assertFalse($settings->email->attachJson);
        self::assertTrue($settings->email->htmlVersion, 'The HTML part is on unless switched off');
        self::assertFalse($this->createFactory()->fromArray(['email' => ['htmlVersion' => '0']], '', '')->email->htmlVersion);
    }

    #[Test]
    public function emailRecipientsAreParsedAndInvalidAddressesDropped(): void
    {
        $settings = $this->createFactory()->fromArray([
            'email' => [
                'enabled' => '1',
                'recipients' => 'support@example.com; not-an-address, dev@example.org ,,support@example.com',
                'senderAddress' => 'typo3@example.com',
                'senderName' => 'TYPO3',
                'subject' => '[{project.name}] {report.title}',
                'bodyTemplate' => 'EXT:context_reporter/Resources/Private/Templates/Email/Report.txt',
                'attachScreenshot' => '0',
                'attachJson' => '1',
                'replyToReporter' => '1',
            ],
        ], '', '');

        self::assertTrue($settings->email->isUsable());
        self::assertSame(['support@example.com', 'dev@example.org'], $settings->email->recipients);
        self::assertSame('typo3@example.com', $settings->email->senderAddress);
        self::assertSame('TYPO3', $settings->email->senderName);
        self::assertSame('[{project.name}] {report.title}', $settings->email->subjectTemplate);
        self::assertFalse($settings->email->attachScreenshot);
        self::assertTrue($settings->email->attachJson);
        self::assertTrue($settings->email->replyToReporter);
    }

    #[Test]
    public function emailWithoutValidRecipientsIsNotUsable(): void
    {
        $settings = $this->createFactory()->fromArray([
            'email' => ['enabled' => '1', 'recipients' => 'nobody'],
        ], '', '');

        self::assertFalse($settings->email->isUsable());
    }

    #[Test]
    public function emailSenderAddressMustBeValid(): void
    {
        $settings = $this->createFactory()->fromArray([
            'email' => ['enabled' => '1', 'recipients' => 'a@example.com', 'senderAddress' => 'invalid'],
        ], '', '');

        self::assertSame('', $settings->email->senderAddress);

        foreach (['a@b' => '', 'typo3@localhost' => '', 'typo3@example.com' => 'typo3@example.com'] as $address => $expected) {
            $settings = $this->createFactory()->fromArray(['email' => ['senderAddress' => $address]], '', '');
            self::assertSame($expected, $settings->email->senderAddress, $address);
        }
    }

    #[Test]
    public function webhookRequiresHttpsUnlessInsecureHttpIsAllowed(): void
    {
        $factory = $this->createFactory();

        $https = $factory->fromArray(['webhook' => ['enabled' => '1', 'url' => 'https://hooks.example.com/report']], '', '');
        self::assertTrue($https->webhook->isUsable());
        self::assertSame('https://hooks.example.com/report', $https->webhook->url);

        $http = $factory->fromArray(['webhook' => ['enabled' => '1', 'url' => 'http://n8n.local/webhook/abc']], '', '');
        self::assertFalse($http->webhook->isUsable());
        self::assertSame('', $http->webhook->url);

        $allowed = $factory->fromArray(['webhook' => ['enabled' => '1', 'url' => 'http://n8n.local/webhook/abc', 'allowInsecureHttp' => '1']], '', '');
        self::assertTrue($allowed->webhook->isUsable());
    }

    public static function invalidWebhookUrlProvider(): \Generator
    {
        yield 'empty' => [''];
        yield 'relative' => ['/webhook'];
        yield 'ftp scheme' => ['ftp://example.com/hook'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'missing host' => ['https:///hook'];
        yield 'whitespace inside' => ['https://example.com/ho ok'];
        yield 'user and password' => ['https://user:secret@example.com/hook'];
        yield 'user only' => ['https://user@example.com/hook'];
    }

    #[Test]
    #[DataProvider('invalidWebhookUrlProvider')]
    public function invalidWebhookUrlsAreRejected(string $url): void
    {
        $settings = $this->createFactory()->fromArray(['webhook' => ['enabled' => '1', 'url' => $url]], '', '');

        self::assertFalse($settings->webhook->isUsable());
    }

    #[Test]
    public function webhookSecretsCanBeReadFromEnvironmentVariables(): void
    {
        $factory = $this->createFactory([
            'CR_WEBHOOK_SECRET' => 'from-env',
            'CR_WEBHOOK_TOKEN' => 'Bearer env-token',
        ]);

        $settings = $factory->fromArray([
            'webhook' => [
                'enabled' => '1',
                'url' => 'https://hooks.example.com',
                'secret' => '%env(CR_WEBHOOK_SECRET)%',
                'authHeaderName' => 'Authorization',
                'authHeaderValue' => '%env(CR_WEBHOOK_TOKEN)%',
            ],
        ], '', '');

        self::assertSame('from-env', $settings->webhook->secret);
        self::assertSame('Bearer env-token', $settings->webhook->authHeaderValue);
    }

    #[Test]
    public function unsetEnvironmentVariableResolvesToEmptySecret(): void
    {
        $settings = $this->createFactory()->fromArray([
            'webhook' => ['enabled' => '1', 'url' => 'https://hooks.example.com', 'secret' => '%env(DOES_NOT_EXIST)%'],
        ], '', '');

        self::assertSame('', $settings->webhook->secret);
    }

    #[Test]
    public function webhookUrlCanBeReadFromEnvironmentVariable(): void
    {
        $settings = $this->createFactory(['CR_WEBHOOK_URL' => 'https://hooks.example.com/abc'])->fromArray([
            'webhook' => ['enabled' => '1', 'url' => '%env(CR_WEBHOOK_URL)%'],
        ], '', '');

        self::assertSame('https://hooks.example.com/abc', $settings->webhook->url);
    }

    #[Test]
    public function invalidAuthorizationHeaderNameDisablesTheHeader(): void
    {
        $settings = $this->createFactory()->fromArray([
            'webhook' => [
                'enabled' => '1',
                'url' => 'https://hooks.example.com',
                'authHeaderName' => "X-Evil\r\nInjected: yes",
                'authHeaderValue' => 'secret',
            ],
        ], '', '');

        self::assertSame('', $settings->webhook->authHeaderName);
        self::assertSame('', $settings->webhook->authHeaderValue);
    }

    #[Test]
    public function webhookTimeoutIsClamped(): void
    {
        $factory = $this->createFactory();

        self::assertSame(10, $factory->fromArray([], '', '')->webhook->timeoutSeconds);
        self::assertSame(1, $factory->fromArray(['webhook' => ['timeout' => '0']], '', '')->webhook->timeoutSeconds);
        self::assertSame(30, $factory->fromArray(['webhook' => ['timeout' => '300']], '', '')->webhook->timeoutSeconds);
    }

    #[Test]
    public function hubIsASignedWebhookWithoutAuthenticationHeader(): void
    {
        $settings = $this->createFactory()->fromArray([
            'webhook' => ['enabled' => '1', 'url' => 'https://hooks.example.com/hook', 'secret' => 'webhook-secret', 'authHeaderValue' => 'Bearer webhook-token', 'timeout' => '3'],
            'hub' => ['enabled' => '1', 'url' => ' https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P ', 'secret' => 'whsec_localDevSecretDemoWebsite0000000001', 'includeScreenshot' => '0', 'authHeaderName' => 'X-Api-Key', 'authHeaderValue' => 'ignored', 'timeout' => '1'],
        ], '', '');

        self::assertTrue($settings->hub->isUsable());
        self::assertSame('https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P', $settings->hub->url);
        self::assertSame('whsec_localDevSecretDemoWebsite0000000001', $settings->hub->secret);
        self::assertSame('', $settings->hub->authHeaderName, 'The Hub authenticates by signature only');
        self::assertSame('', $settings->hub->authHeaderValue);
        self::assertFalse($settings->hub->includeScreenshot);
        self::assertSame(HubIntake::TIMEOUT_SECONDS, $settings->hub->timeoutSeconds);
        self::assertSame('https://hub.example.com', $settings->hub->getDisplayTarget());
        self::assertSame('https://hooks.example.com/hook', $settings->webhook->url, 'The webhook keeps its own settings');
        self::assertSame('Bearer webhook-token', $settings->webhook->authHeaderValue);
        self::assertSame(3, $settings->webhook->timeoutSeconds);
    }

    #[Test]
    public function hubValuesThatCannotBelongToAHubSiteAreDropped(): void
    {
        $factory = $this->createFactory();
        $hub = static fn(array $values) => $factory->fromArray(['hub' => $values + ['enabled' => '1']], '', '')->hub;

        self::assertSame('', $hub(['url' => 'https://hooks.example.com/hook'])->url);
        self::assertSame('', $hub(['url' => 'http://localhost:3210/in/s_demoWebsiteLocalDev000001'])->url, 'http needs the local Hub option');
        self::assertSame('http://localhost:3210/in/s_demoWebsiteLocalDev000001', $hub(['url' => 'http://localhost:3210/in/s_demoWebsiteLocalDev000001', 'allowInsecureHttp' => '1'])->url);
        self::assertSame('', $hub(['secret' => 'not-a-hub-secret'])->secret);
        self::assertFalse($hub(['url' => 'https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P', 'enabled' => '0'])->isUsable());
    }

    #[Test]
    public function theHubAddressForLinksIsTheTrustedLinkBase(): void
    {
        $factory = $this->createFactory();
        $hub = static fn(array $values) => $factory->fromArray(['hub' => $values + ['enabled' => '1', 'url' => 'https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P']], '', '')->hub;

        self::assertSame('https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P', $hub([])->getHubLinkBase(), 'Without it, the webhook URL');
        self::assertSame('https://hub.example.org', $hub(['publicUrl' => 'https://hub.example.org/'])->getHubLinkBase());
        self::assertSame('https://example.com/hub', $hub(['publicUrl' => 'https://example.com/hub'])->publicUrl);
        self::assertSame('', $hub(['publicUrl' => 'http://localhost:3210'])->publicUrl, 'http needs the local Hub option');
        self::assertSame('http://localhost:3210', $hub(['publicUrl' => 'http://localhost:3210', 'allowInsecureHttp' => '1'])->publicUrl);
        foreach (['javascript:alert(1)', 'https://user:pass@hub.example.org', 'https://hub.example.org/?token=1', 'https://hub.example.org/#app', '//hub.example.org'] as $invalid) {
            self::assertSame('', $hub(['publicUrl' => $invalid])->publicUrl, $invalid);
            self::assertSame('https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P', $hub(['publicUrl' => $invalid])->getHubLinkBase());
        }
    }

    #[Test]
    public function hubValuesCanBeReadFromEnvironmentVariables(): void
    {
        $settings = $this->createFactory([
            'CR_HUB_URL' => 'https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P',
            'CR_HUB_SECRET' => 'whsec_localDevSecretDemoWebsite0000000001',
            'CR_BAD_SECRET' => 'plain-value',
        ])->fromArray(['hub' => ['enabled' => '1', 'url' => '%env(CR_HUB_URL)%', 'secret' => '%env(CR_HUB_SECRET)%']], '', '');

        self::assertSame('https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P', $settings->hub->url);
        self::assertSame('whsec_localDevSecretDemoWebsite0000000001', $settings->hub->secret);
        self::assertSame('', $this->createFactory(['CR_BAD_SECRET' => 'plain-value'])->fromArray(['hub' => ['secret' => '%env(CR_BAD_SECRET)%']], '', '')->hub->secret);
    }

    /**
     * @param array<string, string> $environment
     */
    private function createFactory(array $environment = []): ExtensionSettingsFactory
    {
        return new ExtensionSettingsFactory(
            static fn(string $name): string|false => $environment[$name] ?? false,
        );
    }
}
