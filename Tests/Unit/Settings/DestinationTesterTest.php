<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Settings;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\ExtensionInfo;
use Priebera\ContextReporter\Delivery\Email\MailConfigurationInspector;
use Priebera\ContextReporter\Delivery\Webhook\ResponseReferenceExtractor;
use Priebera\ContextReporter\Delivery\Webhook\WebhookClient;
use Priebera\ContextReporter\Delivery\Webhook\WebhookSignature;
use Priebera\ContextReporter\Export\JsonReportExporter;
use Priebera\ContextReporter\Settings\DestinationTester;
use Priebera\ContextReporter\Tests\Unit\Fixtures\RecordingMailer;
use Priebera\ContextReporter\Tests\Unit\Fixtures\SettingsProviderStub;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class DestinationTesterTest extends UnitTestCase
{
    private const URL = 'https://hooks.example.com/webhook/5f1c-secret-path';

    /**
     * @var list<array{request: RequestInterface, options: array<string, mixed>}>
     */
    private array $sentRequests = [];

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP'], $GLOBALS['TYPO3_CONF_VARS']['MAIL']);
        parent::tearDown();
    }

    #[Test]
    public function testEmailIsSentToTheConfiguredRecipientsEvenWhenEmailIsDisabled(): void
    {
        $mailer = new RecordingMailer();
        $tester = $this->createTester([
            'general' => ['projectName' => 'Agency Portal', 'environment' => 'Staging'],
            'email' => ['enabled' => '0', 'recipients' => 'support@example.com dev@example.com', 'senderAddress' => 'portal@example.com', 'senderName' => 'Portal'],
        ], $mailer);

        $outcome = $tester->sendTestEmail();

        self::assertTrue($outcome->successful, $outcome->message);
        self::assertSame('Test email handed over to the mail transport for 2 recipients.', $outcome->message);
        self::assertCount(1, $mailer->messages);
        $message = $mailer->messages[0];
        self::assertSame('[Agency Portal] Context Reporter test email', $message->getSubject());
        self::assertSame(['support@example.com', 'dev@example.com'], array_map(static fn($address): string => $address->getAddress(), $message->getTo()));
        self::assertSame('portal@example.com', $message->getFrom()[0]->getAddress());
        self::assertSame('Portal', $message->getFrom()[0]->getName());
        self::assertSame('1', $message->getHeaders()->get('X-Context-Reporter-Test')?->getBodyAsString());
        self::assertSame([], $message->getAttachments());
        $body = (string)$message->getTextBody();
        self::assertStringContainsString('This is a test email from TYPO3 Context Reporter.', $body);
        self::assertStringContainsString('Project: Agency Portal (Staging)', $body);
        self::assertStringContainsString('Email delivery of reports is switched off.', $body);
    }

    #[Test]
    public function testEmailNeedsRecipients(): void
    {
        $mailer = new RecordingMailer();

        $outcome = $this->createTester(['email' => ['enabled' => '1', 'recipients' => '']], $mailer)->sendTestEmail();

        self::assertFalse($outcome->successful);
        self::assertSame('No email recipients are configured.', $outcome->message);
        self::assertSame([], $mailer->messages);
    }

    #[Test]
    public function testEmailFailuresDoNotRevealMailCredentials(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = ['transport' => 'smtp', 'transport_smtp_server' => 'smtp.example.com:465', 'transport_smtp_username' => 'mailer-account', 'transport_smtp_password' => 'mailer-password'];
        $mailer = new RecordingMailer(new TransportException('Failed to authenticate on SMTP server with username "mailer-account" (password mailer-password).'));

        $outcome = $this->createTester(['email' => ['recipients' => 'support@example.com']], $mailer)->sendTestEmail();

        self::assertFalse($outcome->successful);
        self::assertSame('TransportException: Failed to authenticate on SMTP server with username "[redacted]" (password [redacted]).', $outcome->message);
    }

    #[Test]
    public function testWebhookIsSignedAndAuthenticatedLikeAReport(): void
    {
        $this->respondWith(new Response(204));
        $tester = $this->createTester([
            'general' => ['projectName' => 'Agency Portal', 'projectIdentifier' => 'portal', 'environment' => 'Staging'],
            'webhook' => ['enabled' => '0', 'url' => self::URL, 'secret' => 'hmac-secret', 'authHeaderName' => 'X-Api-Key', 'authHeaderValue' => 'key-123', 'timeout' => '5'],
        ]);

        $outcome = $tester->sendTestWebhook();

        self::assertTrue($outcome->successful, $outcome->message);
        self::assertSame('HTTP 204', $outcome->message);
        self::assertCount(1, $this->sentRequests);
        $request = $this->sentRequests[0]['request'];
        $body = (string)$request->getBody();
        self::assertSame(self::URL, (string)$request->getUri());
        self::assertSame('test', $request->getHeaderLine('X-Context-Reporter-Event'));
        self::assertSame('key-123', $request->getHeaderLine('X-Api-Key'));
        self::assertFalse($request->hasHeader('X-Context-Reporter-Report'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $request->getHeaderLine('X-Context-Reporter-Delivery'));
        self::assertTrue(WebhookSignature::verify($request->getHeaderLine(WebhookSignature::HEADER), 'hmac-secret', $body));
        self::assertSame(5, $this->sentRequests[0]['options']['timeout']);
        self::assertFalse($this->sentRequests[0]['options']['allow_redirects']);

        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('test', $payload['event']);
        self::assertSame($request->getHeaderLine('X-Context-Reporter-Delivery'), $payload['delivery']['id']);
        self::assertSame(['name' => 'Agency Portal', 'identifier' => 'portal', 'environment' => 'Staging'], $payload['test']['project']);
        self::assertArrayNotHasKey('report', $payload);
        self::assertStringNotContainsString('hmac-secret', $body);
        self::assertStringNotContainsString('key-123', $body);
    }

    #[Test]
    public function testWebhookNeedsAUsableEndpoint(): void
    {
        $outcome = $this->createTester(['webhook' => ['enabled' => '1', 'url' => '%env(CR_UNSET_WEBHOOK_URL)%']])->sendTestWebhook();

        self::assertFalse($outcome->successful);
        self::assertSame('No usable webhook endpoint is configured.', $outcome->message);
        self::assertSame([], $this->sentRequests);
    }

    #[Test]
    public function testWebhookFailuresAreRedacted(): void
    {
        $this->respondWith(new Response(401, [], 'Token Bearer token-123 is not valid for ' . self::URL));

        $outcome = $this->createTester(['webhook' => ['url' => self::URL, 'authHeaderValue' => 'Bearer token-123']])->sendTestWebhook();

        self::assertFalse($outcome->successful);
        self::assertSame(401, $outcome->responseCode);
        self::assertSame('HTTP 401: Token [redacted] is not valid for [url]', $outcome->message);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function createTester(array $configuration, ?RecordingMailer $mailer = null): DestinationTester
    {
        return new DestinationTester(
            new SettingsProviderStub($configuration),
            $mailer ?? new RecordingMailer(),
            new MailConfigurationInspector(),
            new WebhookClient(new RequestFactory(new GuzzleClientFactory()), new ResponseReferenceExtractor(), new ExtensionInfo('1.2.3')),
            new JsonReportExporter(),
            new ExtensionInfo('1.2.3'),
        );
    }

    private function respondWith(Response $response): void
    {
        $sentRequests = &$this->sentRequests;
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = [
            'verify' => true,
            'handler' => [
                'contextReporterTest' => static function (callable $next) use (&$sentRequests, $response): \Closure {
                    return static function (RequestInterface $request, array $options) use (&$sentRequests, $response) {
                        $sentRequests[] = ['request' => $request, 'options' => $options];
                        return Create::promiseFor($response);
                    };
                },
            ],
        ];
    }
}
