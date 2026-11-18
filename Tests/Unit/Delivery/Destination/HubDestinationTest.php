<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Delivery\Destination;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\ExtensionInfo;
use Priebera\ContextReporter\Configuration\HubIntake;
use Priebera\ContextReporter\Delivery\DeliveryRequest;
use Priebera\ContextReporter\Delivery\Destination\HubDestination;
use Priebera\ContextReporter\Delivery\Destination\WebhookDestination;
use Priebera\ContextReporter\Delivery\Http\BoundedHttpClient;
use Priebera\ContextReporter\Delivery\Webhook\ResponseReferenceExtractor;
use Priebera\ContextReporter\Delivery\Webhook\WebhookClient;
use Priebera\ContextReporter\Delivery\Webhook\WebhookReportSender;
use Priebera\ContextReporter\Delivery\Webhook\WebhookSignature;
use Priebera\ContextReporter\Export\JsonReportExporter;
use Priebera\ContextReporter\Export\ReportPayloadFactory;
use Priebera\ContextReporter\Hub\HubAcknowledgement;
use Priebera\ContextReporter\Tests\Unit\Fixtures\ReportFixture;
use Priebera\ContextReporter\Tests\Unit\Fixtures\SettingsProviderStub;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class HubDestinationTest extends UnitTestCase
{
    private const URL = 'https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P';
    private const SECRET = 'whsec_8Fq2LmZx4RtY7vB1nKc3Wd9PaQ5sT0uV';

    /**
     * @var list<array{request: RequestInterface, options: array<string, mixed>}>
     */
    private array $sentRequests = [];

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']);
        parent::tearDown();
    }

    #[Test]
    public function reportIsSignedWithTheSiteSecretAndTheHubReferenceIsKept(): void
    {
        $this->respondWith(new Response(200, ['Content-Type' => 'application/json; charset=utf-8'], '{"reference":"LW-152","url":"https://hub.example.com/app/reports/LW-152"}'));

        $outcome = $this->createDestination([])->deliver($this->createRequest());

        self::assertTrue($outcome->successful, $outcome->message);
        self::assertSame(200, $outcome->responseCode);
        self::assertSame('HTTP 200', $outcome->message);
        self::assertSame('LW-152', $outcome->externalReference);
        self::assertSame('https://hub.example.com/app/reports/LW-152', $outcome->externalUrl);

        self::assertCount(1, $this->sentRequests);
        $request = $this->sentRequests[0]['request'];
        $body = (string)$request->getBody();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::URL, (string)$request->getUri());
        self::assertSame('report.created', $request->getHeaderLine('X-Context-Reporter-Event'));
        self::assertSame('CR-7K3Q-9XMA-2B4F', $request->getHeaderLine('X-Context-Reporter-Report'));
        self::assertSame('delivery-uuid', $request->getHeaderLine('X-Context-Reporter-Delivery'));
        self::assertSame('2', $request->getHeaderLine('X-Context-Reporter-Attempt'));
        self::assertTrue(WebhookSignature::verify($request->getHeaderLine(WebhookSignature::HEADER), self::SECRET, $body));
        self::assertFalse($request->hasHeader('Authorization'));
        self::assertFalse($this->sentRequests[0]['options']['allow_redirects']);
        self::assertSame((float)HubIntake::TIMEOUT_SECONDS, $this->sentRequests[0]['options']['timeout']);

        $envelope = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('report.created', $envelope['event']);
        self::assertSame('context-reporter.report.v1', $envelope['report']['schema']);
        self::assertSame('CR-7K3Q-9XMA-2B4F', $envelope['report']['id']);
        self::assertSame(base64_encode('png-bytes'), $envelope['report']['attachments'][0]['contentBase64']);
        self::assertStringNotContainsString(self::SECRET, $body);
        self::assertStringNotContainsString('s_8Fq2LmZx4RtY7vB1nKc3Wd9P', $body);
    }

    #[Test]
    public function theHubReceivesExactlyTheWebhookRequest(): void
    {
        $this->respondWith(new Response(204));
        $configuration = [
            'webhook' => ['enabled' => '1', 'url' => self::URL, 'secret' => self::SECRET],
            'hub' => ['enabled' => '1', 'url' => self::URL, 'secret' => self::SECRET],
        ];

        $this->createDestination([], $configuration)->deliver($this->createRequest());
        (new WebhookDestination(new SettingsProviderStub($configuration), $this->createSender()))->deliver($this->createRequest());

        self::assertCount(2, $this->sentRequests);
        [$hub, $webhook] = [$this->sentRequests[0]['request'], $this->sentRequests[1]['request']];
        $varying = ['X-Context-Reporter-Timestamp', WebhookSignature::HEADER];
        self::assertSame(
            array_diff_key($webhook->getHeaders(), array_flip($varying)),
            array_diff_key($hub->getHeaders(), array_flip($varying)),
        );
        $normalize = static function (RequestInterface $request): array {
            $envelope = json_decode((string)$request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            unset($envelope['delivery']['sentAt']);
            return $envelope;
        };
        self::assertSame($normalize($webhook), $normalize($hub));
        self::assertTrue(WebhookSignature::verify($hub->getHeaderLine(WebhookSignature::HEADER), self::SECRET, (string)$hub->getBody()));
    }

    #[Test]
    public function theWebhookKeepsItsOwnEndpointAndAuthentication(): void
    {
        $this->respondWith(new Response(204));
        $configuration = [
            'webhook' => ['enabled' => '1', 'url' => 'https://hooks.example.com/hook', 'secret' => 'webhook-secret', 'authHeaderValue' => 'Bearer webhook-token'],
            'hub' => ['enabled' => '1', 'url' => self::URL, 'secret' => self::SECRET],
        ];

        $this->createDestination([], $configuration)->deliver($this->createRequest());

        $request = $this->sentRequests[0]['request'];
        self::assertSame(self::URL, (string)$request->getUri());
        self::assertFalse($request->hasHeader('Authorization'));
        self::assertTrue(WebhookSignature::verify($request->getHeaderLine(WebhookSignature::HEADER), self::SECRET, (string)$request->getBody()));
        self::assertStringNotContainsString('webhook-token', (string)$request->getBody());
    }

    #[Test]
    public function aRejectedSignatureIsAFailureWithoutSecrets(): void
    {
        $this->respondWith(new Response(401, ['Content-Type' => 'application/json; charset=utf-8'], '{"error":"invalid_signature","message":"The signature does not match. Copy the current signing secret from Context Reporter Hub into the extension settings."}'));

        $outcome = $this->createDestination([])->deliver($this->createRequest());

        self::assertFalse($outcome->successful);
        self::assertSame(401, $outcome->responseCode);
        self::assertSame('', $outcome->externalReference);
        self::assertStringStartsWith('HTTP 401: {"error":"invalid_signature"', $outcome->message);
    }

    #[Test]
    public function answersEchoingTheWebhookUrlOrSecretAreRedacted(): void
    {
        $this->respondWith(new Response(404, [], 'Unknown ' . self::URL . ' /in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P signed with ' . self::SECRET));

        $outcome = $this->createDestination([])->deliver($this->createRequest());

        self::assertFalse($outcome->successful);
        self::assertSame(404, $outcome->responseCode);
        self::assertStringNotContainsString('s_8Fq2LmZx4RtY7vB1nKc3Wd9P', $outcome->message);
        self::assertStringNotContainsString(self::SECRET, $outcome->message);
    }

    #[Test]
    public function referencesOrLinksCarryingSecretsAreDropped(): void
    {
        $this->respondWith(new Response(200, ['Content-Type' => 'application/json'], '{"reference":"' . self::SECRET . '","url":"' . self::URL . '"}'));
        $echoed = $this->createDestination([])->deliver($this->createRequest());
        self::assertFalse($echoed->successful, 'An echo is not a Hub acknowledgement');
        self::assertSame('', $echoed->externalReference);
        self::assertSame('', $echoed->externalUrl);
        self::assertStringNotContainsString(self::SECRET, $echoed->message);

        $this->respondWith(new Response(200, ['Content-Type' => 'application/json'], '{"reference":"LW-7","url":"https://hub.example.com/app/reports/LW-7?token=abc123"}'));
        $tokenLink = $this->createDestination([])->deliver($this->createRequest());
        self::assertSame('LW-7', $tokenLink->externalReference);
        self::assertSame('https://hub.example.com/app/reports/LW-7', $tokenLink->externalUrl);
    }

    /**
     * @return iterable<string, array{Response}>
     */
    public static function answersWithoutAcknowledgement(): iterable
    {
        yield 'no content' => [new Response(204)];
        yield 'empty' => [new Response(200, ['Content-Type' => 'application/json'], '')];
        yield 'login page' => [new Response(200, ['Content-Type' => 'text/html'], '<html><title>Sign in</title></html>')];
        yield 'malformed JSON' => [new Response(200, ['Content-Type' => 'application/json'], '{"reference":"LW-1"')];
        yield 'cut off' => [new Response(200, ['Content-Type' => 'application/json'], '{"reference":"LW-1","url":"https://hub.ex')];
        yield 'no reference' => [new Response(200, ['Content-Type' => 'application/json'], '{"status":"ok"}')];
        yield 'not a Hub reference' => [new Response(201, ['Content-Type' => 'application/json'], '{"reference":"ticket 42"}')];
        yield 'reference is a link' => [new Response(200, ['Content-Type' => 'application/json'], '{"reference":"javascript:alert(1)"}')];
        yield 'too large' => [new Response(200, ['Content-Type' => 'application/json'], '{"reference":"LW-1","pad":"' . str_repeat('a', 70000) . '"}')];
    }

    /**
     * Unlike the generic webhook, a 2xx alone does not mean the Hub received the
     * report: the delivery fails and can be retried (the Hub keeps one report per
     * report ID and answers a retry with the existing reference).
     */
    #[Test]
    #[DataProvider('answersWithoutAcknowledgement')]
    public function onlyAHubAcknowledgementCountsAsReceived(Response $answer): void
    {
        $this->respondWith($answer);

        $outcome = $this->createDestination([])->deliver($this->createRequest());

        self::assertFalse($outcome->successful);
        self::assertSame($answer->getStatusCode(), $outcome->responseCode);
        self::assertStringContainsString('the Hub did not confirm the report', $outcome->message);
        self::assertSame('', $outcome->externalReference);
        self::assertSame('', $outcome->externalUrl);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function links(): iterable
    {
        $docker = 'http://host.docker.internal:3210/in/s_demoWebsiteLocalDev000001';
        $local = ['url' => $docker, 'allowInsecureHttp' => '1'];
        yield 'on the webhook URL' => ['https://hub.example.com/app/reports/LW-152', [], 'https://hub.example.com/app/reports/LW-152'];
        yield 'another host' => ['https://evil.example.com/app/reports/LW-152', [], ''];
        yield 'another scheme' => ['http://hub.example.com/app/reports/LW-152', [], ''];
        yield 'outside the app' => ['https://hub.example.com/admin', [], ''];
        yield 'javascript' => ['javascript:alert(1)', [], ''];
        yield 'data' => ['data:text/html,<b>x</b>', [], ''];
        yield 'credentials' => ['https://user:pass@hub.example.com/app/reports/LW-152', [], ''];
        yield 'browser address, not configured' => ['http://localhost:3210/app/reports/LW-152', $local, ''];
        yield 'browser address configured' => ['http://localhost:3210/app/reports/LW-152', $local + ['publicUrl' => 'http://localhost:3210'], 'http://localhost:3210/app/reports/LW-152'];
        yield 'the webhook URL is no longer trusted' => ['http://host.docker.internal:3210/app/reports/LW-152', $local + ['publicUrl' => 'http://localhost:3210/'], ''];
        yield 'public address with path' => ['https://example.com/hub/app/reports/LW-152', ['publicUrl' => 'https://example.com/hub'], 'https://example.com/hub/app/reports/LW-152'];
        yield 'public address with path, outside' => ['https://example.com/app/reports/LW-152', ['publicUrl' => 'https://example.com/hub'], ''];
        yield 'http public address without allowing http' => ['http://localhost:3210/app/reports/LW-152', ['publicUrl' => 'http://localhost:3210'], ''];
    }

    /**
     * A link is shown only on the trusted Hub address: the "Hub address for links"
     * when set (TYPO3 in Docker reaches the Hub at host.docker.internal, the
     * browser at localhost), otherwise the webhook URL. The reference is kept.
     *
     * @param array<string, string> $hubConfiguration
     */
    #[Test]
    #[DataProvider('links')]
    public function linksAreOnlyKeptOnTheTrustedHubAddress(string $link, array $hubConfiguration, string $expected): void
    {
        $this->respondWith(new Response(200, ['Content-Type' => 'application/json'], json_encode(['reference' => 'LW-152', 'url' => $link], JSON_THROW_ON_ERROR)));

        $outcome = $this->createDestination($hubConfiguration)->deliver($this->createRequest());

        self::assertTrue($outcome->successful, $outcome->message);
        self::assertSame('LW-152', $outcome->externalReference);
        self::assertSame($expected, $outcome->externalUrl);
    }

    /**
     * Newer Hubs say whether they stored the screenshot. A report received
     * without its screenshot (storage of the workspace full) is still received.
     */
    #[Test]
    public function aReportReceivedWithoutItsScreenshotIsReceived(): void
    {
        $this->respondWith(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'reference' => 'LW-152',
            'url' => 'https://hub.example.com/app/reports/LW-152',
            'screenshots' => ['received' => 1, 'stored' => 0, 'omitted' => 1, 'reasons' => ['storage_full']],
            'storage' => 'full',
        ], JSON_THROW_ON_ERROR)));

        $outcome = $this->createDestination([])->deliver($this->createRequest());

        self::assertTrue($outcome->successful, $outcome->message);
        self::assertSame('HTTP 200', $outcome->message);
        self::assertSame('LW-152', $outcome->externalReference);
        self::assertSame('https://hub.example.com/app/reports/LW-152', $outcome->externalUrl);
        self::assertNotNull($outcome->hubReceipt);
        self::assertTrue($outcome->hubReceipt->hasOmittedScreenshots());
        self::assertSame('storage_full', $outcome->hubReceipt->getPrimaryReason());
        self::assertSame('full', $outcome->hubReceipt->storage);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acknowledgementsWithoutUsableDetails(): iterable
    {
        yield 'older Hub' => ['{"reference":"LW-152","url":"https://hub.example.com/app/reports/LW-152"}'];
        yield 'details of the wrong type' => ['{"reference":"LW-152","screenshots":"storage_full","storage":90}'];
        yield 'list instead of object' => ['{"reference":"LW-152","screenshots":[1,0,1],"storage":"FULL"}'];
        yield 'negative and fractional counts' => ['{"reference":"LW-152","screenshots":{"received":-1,"stored":0.5,"omitted":"1"}}'];
    }

    /**
     * The optional keys never turn an acknowledgement into a failure.
     */
    #[Test]
    #[DataProvider('acknowledgementsWithoutUsableDetails')]
    public function missingOrMalformedDetailsKeepTheAcknowledgement(string $answer): void
    {
        $this->respondWith(new Response(200, ['Content-Type' => 'application/json'], $answer));

        $outcome = $this->createDestination([])->deliver($this->createRequest());

        self::assertTrue($outcome->successful, $outcome->message);
        self::assertSame('LW-152', $outcome->externalReference);
        self::assertNull($outcome->hubReceipt);
    }

    #[Test]
    public function connectionErrorsBecomeFailuresWithoutTheUrl(): void
    {
        $this->respondWith(new ConnectException(
            'cURL error 7: Failed to connect to localhost port 3210 after 0 ms: Connection refused (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for ' . self::URL,
            new \GuzzleHttp\Psr7\Request('POST', self::URL),
        ));

        $outcome = $this->createDestination([])->deliver($this->createRequest());

        self::assertFalse($outcome->successful);
        self::assertNull($outcome->responseCode);
        self::assertSame('The server could not be reached (connection refused or blocked) (cURL error 7).', $outcome->message);
        self::assertFalse(HubAcknowledgement::isUnconfirmed($outcome->message));
        self::assertStringNotContainsString('s_8Fq2LmZx4RtY7vB1nKc3Wd9P', $outcome->message);
    }

    /**
     * @return iterable<string, array{\Throwable, string}>
     */
    public static function failuresAfterTheRequestWasSent(): iterable
    {
        $request = new \GuzzleHttp\Psr7\Request('POST', self::URL);
        yield 'timeout' => [
            new ConnectException('cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for ' . self::URL, $request),
            'No answer within the time limit (timeout) (cURL error 28).',
        ];
        yield 'empty reply' => [
            new \GuzzleHttp\Exception\RequestException('cURL error 52: Empty reply from server (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for ' . self::URL, $request),
            'The server closed the connection without an answer (cURL error 52).',
        ];
        yield 'connection reset while waiting' => [
            new \GuzzleHttp\Exception\RequestException('cURL error 56: Recv failure: Connection reset by peer (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for ' . self::URL, $request),
            'The server closed the connection without an answer (cURL error 56).',
        ];
    }

    /**
     * The Hub may have stored the report although its answer never arrived (it stores first and answers then). Such a
     * delivery is not reported as "not sent": it is unconfirmed, and the message says that a retry is safe.
     */
    #[Test]
    #[DataProvider('failuresAfterTheRequestWasSent')]
    public function aDeliveryWhoseAnswerWasLostIsUnconfirmedNotFailed(\Throwable $failure, string $cause): void
    {
        $this->respondWith($failure);

        $outcome = $this->createDestination([])->deliver($this->createRequest());

        self::assertFalse($outcome->successful);
        self::assertTrue(HubAcknowledgement::isUnconfirmed($outcome->message), $outcome->message);
        self::assertStringStartsWith(HubAcknowledgement::UNCONFIRMED, $outcome->message);
        self::assertStringContainsString($cause, $outcome->message);
        self::assertStringContainsString('may have received the report', $outcome->message);
        self::assertStringContainsString('a retry is safe', $outcome->message);
        self::assertStringNotContainsString('s_8Fq2LmZx4RtY7vB1nKc3Wd9P', $outcome->message);
    }

    #[Test]
    public function aDeliveryThatNeverReachedTheHubStaysAPlainFailure(): void
    {
        $this->respondWith(new ConnectException(
            'cURL error 6: Could not resolve host: hub.example.com (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for ' . self::URL,
            new \GuzzleHttp\Psr7\Request('POST', self::URL),
        ));

        $outcome = $this->createDestination([])->deliver($this->createRequest());

        self::assertFalse($outcome->successful);
        self::assertFalse(HubAcknowledgement::isUnconfirmed($outcome->message));
        self::assertSame('The host name could not be resolved (DNS) (cURL error 6).', $outcome->message);
    }

    #[Test]
    public function theDestinationNeedsAWebhookUrlAndASecret(): void
    {
        self::assertTrue($this->createDestination([])->isEnabled());
        self::assertSame('https://hub.example.com', $this->createDestination([])->describeTarget());
        self::assertFalse($this->createDestination(['enabled' => '0'])->isEnabled());
        self::assertFalse($this->createDestination(['secret' => ''])->isEnabled(), 'The Hub rejects unsigned deliveries');
        self::assertFalse($this->createDestination(['url' => 'https://hooks.example.com/hook'])->isEnabled());
        self::assertFalse($this->createDestination(['url' => 'http://localhost:3210/in/s_demoWebsiteLocalDev000001'])->isEnabled());
        self::assertTrue($this->createDestination(['url' => 'http://localhost:3210/in/s_demoWebsiteLocalDev000001', 'allowInsecureHttp' => '1'])->isEnabled());
        self::assertFalse($this->createDestination([], ['webhook' => ['enabled' => '1', 'url' => self::URL, 'secret' => self::SECRET]])->isEnabled(), 'The webhook settings do not enable the Hub');
    }

    #[Test]
    public function aLargeScreenshotGetsTimeForItsUpload(): void
    {
        $this->respondWith(new Response(200, ['Content-Type' => 'application/json; charset=utf-8'], '{"reference":"LW-153","url":"https://hub.example.com/app/reports/LW-153"}'));
        $request = new DeliveryRequest(ReportFixture::report(), 'https://example.com/typo3/report/CR', 1, 'delivery-uuid', static fn(): string => str_repeat('x', 3 * 1048576));

        $outcome = $this->createDestination([])->deliver($request);

        self::assertTrue($outcome->successful, $outcome->message);
        $size = strlen((string)$this->sentRequests[0]['request']->getBody());
        self::assertGreaterThan(4 * 1048576, $size);
        self::assertSame(HubIntake::deadlineSeconds($size), $this->sentRequests[0]['options']['timeout']);
        self::assertGreaterThan((float)HubIntake::TIMEOUT_SECONDS + 10.0, $this->sentRequests[0]['options']['timeout']);
        self::assertLessThanOrEqual((float)HubIntake::MAX_TIMEOUT_SECONDS, $this->sentRequests[0]['options']['timeout']);
    }

    private function createRequest(): DeliveryRequest
    {
        return new DeliveryRequest(ReportFixture::report(), 'https://example.com/typo3/report/CR', 2, 'delivery-uuid', static fn(): string => 'png-bytes');
    }

    /**
     * @param array<string, string> $hubConfiguration Merged into a usable Hub configuration
     * @param array<string, mixed>|null $configuration The complete configuration instead
     */
    private function createDestination(array $hubConfiguration, ?array $configuration = null): HubDestination
    {
        $configuration ??= ['hub' => array_merge(['enabled' => '1', 'url' => self::URL, 'secret' => self::SECRET], $hubConfiguration)];
        return new HubDestination(new SettingsProviderStub($configuration), $this->createSender());
    }

    private function createSender(): WebhookReportSender
    {
        return new WebhookReportSender(
            new WebhookClient(new BoundedHttpClient(new RequestFactory(new GuzzleClientFactory())), new ResponseReferenceExtractor(), new ExtensionInfo('1.2.3')),
            new ReportPayloadFactory(new ExtensionInfo('1.2.3')),
            new JsonReportExporter(),
        );
    }

    private function respondWith(Response|\Throwable $result): void
    {
        $sentRequests = &$this->sentRequests;
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = [
            'verify' => true,
            'handler' => [
                'contextReporterTest' => static function (callable $next) use (&$sentRequests, $result): \Closure {
                    return static function (RequestInterface $request, array $options) use (&$sentRequests, $result) {
                        $sentRequests[] = ['request' => $request, 'options' => $options];
                        return $result instanceof \Throwable ? Create::rejectionFor($result) : Create::promiseFor($result);
                    };
                },
            ],
        ];
    }
}
