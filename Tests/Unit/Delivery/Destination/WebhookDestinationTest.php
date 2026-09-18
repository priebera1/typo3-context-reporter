<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Delivery\Destination;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\ExtensionInfo;
use Priebera\ContextReporter\Delivery\DeliveryRequest;
use Priebera\ContextReporter\Delivery\Destination\WebhookDestination;
use Priebera\ContextReporter\Delivery\Webhook\ResponseReferenceExtractor;
use Priebera\ContextReporter\Delivery\Webhook\WebhookClient;
use Priebera\ContextReporter\Delivery\Webhook\WebhookSignature;
use Priebera\ContextReporter\Export\JsonReportExporter;
use Priebera\ContextReporter\Export\ReportPayloadFactory;
use Priebera\ContextReporter\Tests\Unit\Fixtures\ReportFixture;
use Priebera\ContextReporter\Tests\Unit\Fixtures\SettingsProviderStub;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class WebhookDestinationTest extends UnitTestCase
{
    private const URL = 'https://hooks.example.com/webhook/5f1c-secret-path';

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
    public function reportIsPostedAsSignedJsonWithAuthorizationHeader(): void
    {
        $this->respondWith(new Response(201, ['Content-Type' => 'application/json'], '{"reference":"SUP-42","url":"https://desk.example.com/42"}'));
        $destination = $this->createDestination([
            'secret' => 'hmac-secret',
            'authHeaderName' => 'Authorization',
            'authHeaderValue' => 'Bearer token-123',
        ]);

        $outcome = $destination->deliver($this->createRequest());

        self::assertTrue($outcome->successful);
        self::assertSame(201, $outcome->responseCode);
        self::assertSame('SUP-42', $outcome->externalReference);
        self::assertSame('https://desk.example.com/42', $outcome->externalUrl);

        self::assertCount(1, $this->sentRequests);
        $request = $this->sentRequests[0]['request'];
        $options = $this->sentRequests[0]['options'];
        $body = (string)$request->getBody();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::URL, (string)$request->getUri());
        self::assertSame('application/json; charset=utf-8', $request->getHeaderLine('Content-Type'));
        self::assertSame('Bearer token-123', $request->getHeaderLine('Authorization'));
        self::assertSame('report.created', $request->getHeaderLine('X-Context-Reporter-Event'));
        self::assertSame('CR-7K3Q-9XMA-2B4F', $request->getHeaderLine('X-Context-Reporter-Report'));
        self::assertSame('2', $request->getHeaderLine('X-Context-Reporter-Attempt'));
        self::assertSame('TYPO3-Context-Reporter/1.2.3', $request->getHeaderLine('User-Agent'));
        self::assertTrue(WebhookSignature::verify(
            $request->getHeaderLine(WebhookSignature::HEADER),
            'hmac-secret',
            $body,
        ));
        self::assertStringStartsWith('t=' . $request->getHeaderLine('X-Context-Reporter-Timestamp') . ',', $request->getHeaderLine(WebhookSignature::HEADER));
        self::assertFalse($options['allow_redirects']);
        self::assertFalse($options['http_errors']);
        self::assertSame(10, $options['timeout']);

        $envelope = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('report.created', $envelope['event']);
        self::assertSame(['id' => 'delivery-uuid', 'attempt' => 2], array_intersect_key($envelope['delivery'], ['id' => 0, 'attempt' => 0]));
        self::assertSame('CR-7K3Q-9XMA-2B4F', $envelope['report']['id']);
        self::assertSame('https://example.com/typo3/report/CR', $envelope['report']['links']['report']);
        self::assertSame(base64_encode('png-bytes'), $envelope['report']['attachments'][0]['contentBase64']);
        self::assertStringNotContainsString('hmac-secret', $body);
        self::assertStringNotContainsString('token-123', $body);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function echoedSecretProvider(): iterable
    {
        yield 'authorization value and endpoint' => ['{"reference":"Bearer token-123","url":"' . self::URL . '"}', '', ''];
        yield 'credential without its scheme' => ['{"reference":"token-123","url":"https://desk.example.com/tickets/token-123"}', '', ''];
        yield 'signing secret' => ['{"id":"hmac-secret","url":"https://desk.example.com/?secret=hmac-secret"}', '', 'https://desk.example.com/'];
        yield 'endpoint path' => ['{"reference":"/webhook/5f1c-secret-path","url":"https://desk.example.com/webhook/5f1c-secret-path"}', '', ''];
        yield 'encoded endpoint' => ['{"reference":"SUP-7","url":"https://desk.example.com/new?source=https%3A%2F%2Fhooks.example.com%2Fwebhook%2F5f1c-secret-path"}', 'SUP-7', ''];
        yield 'url as reference and credentials in url' => ['{"reference":"https://hooks.example.com/x","url":"https://user:pass@desk.example.com/tickets/42"}', '', ''];
        yield 'legitimate ticket' => ['{"ticket":{"key":"SUP-42","url":"https://desk.example.com/tickets/42?view=full&access_token=abc987xyz#comments"}}', 'SUP-42', 'https://desk.example.com/tickets/42?view=full#comments'];
    }

    #[Test]
    #[DataProvider('echoedSecretProvider')]
    public function successfulResponsesCannotEchoSecretsIntoTheReference(string $responseBody, string $reference, string $url): void
    {
        $this->respondWith(new Response(201, ['Content-Type' => 'application/json'], $responseBody));

        $outcome = $this->createDestination(['secret' => 'hmac-secret', 'authHeaderValue' => 'Bearer token-123'])->deliver($this->createRequest());

        self::assertTrue($outcome->successful);
        self::assertSame($reference, $outcome->externalReference);
        self::assertSame($url, $outcome->externalUrl);
        self::assertSame('HTTP 201', $outcome->message);
    }

    #[Test]
    public function screenshotIsOmittedWhenDisabled(): void
    {
        $this->respondWith(new Response(204));
        $loaderCalled = false;
        $request = new DeliveryRequest(ReportFixture::report(), '', 1, 'id', static function () use (&$loaderCalled): string {
            $loaderCalled = true;
            return 'png-bytes';
        });

        $outcome = $this->createDestination(['includeScreenshot' => '0'])->deliver($request);

        self::assertTrue($outcome->successful);
        self::assertFalse($loaderCalled);
        $envelope = json_decode((string)$this->sentRequests[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('contentBase64', $envelope['report']['attachments'][0]);
        self::assertArrayNotHasKey(WebhookSignature::HEADER, $this->sentRequests[0]['request']->getHeaders());
        self::assertFalse($this->sentRequests[0]['request']->hasHeader('Authorization'));
    }

    #[Test]
    public function errorStatusIsAFailureWithRedactedResponseExcerpt(): void
    {
        $this->respondWith(new Response(401, [], 'Invalid token "Bearer token-123" for ' . self::URL));

        $outcome = $this->createDestination(['authHeaderValue' => 'Bearer token-123'])->deliver($this->createRequest());

        self::assertFalse($outcome->successful);
        self::assertSame(401, $outcome->responseCode);
        self::assertSame('HTTP 401: Invalid token "[redacted]" for [url]', $outcome->message);
    }

    #[Test]
    public function responsesEchoingTheEndpointPathAreRedacted(): void
    {
        $this->respondWith(new Response(404, [], 'No workflow listens on /webhook/5f1c-secret-path (query key=abc123)'));

        $outcome = $this->createDestination(['url' => self::URL . '?key=abc123'])->deliver($this->createRequest());

        self::assertFalse($outcome->successful);
        self::assertStringNotContainsString('5f1c-secret-path', $outcome->message);
        self::assertSame('HTTP 404: No workflow listens on [redacted] (query [redacted])', $outcome->message);
    }

    #[Test]
    public function redirectsAreReportedAsFailures(): void
    {
        $this->respondWith(new Response(302, ['Location' => 'https://elsewhere.example.com']));

        $outcome = $this->createDestination([])->deliver($this->createRequest());

        self::assertFalse($outcome->successful);
        self::assertSame('HTTP 302 (redirects are not followed)', $outcome->message);
    }

    #[Test]
    public function connectionErrorsBecomeFailuresWithoutTheUrl(): void
    {
        $this->respondWith(new ConnectException(
            'cURL error 28: Operation timed out after 10001 milliseconds (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for ' . self::URL,
            new \GuzzleHttp\Psr7\Request('POST', self::URL),
        ));

        $outcome = $this->createDestination([])->deliver($this->createRequest());

        self::assertFalse($outcome->successful);
        self::assertNull($outcome->responseCode);
        self::assertStringStartsWith('ConnectException: cURL error 28: Operation timed out', $outcome->message);
        self::assertStringNotContainsString('secret-path', $outcome->message);
    }

    #[Test]
    public function targetDescriptionShowsOnlySchemeAndHost(): void
    {
        self::assertSame('https://hooks.example.com', $this->createDestination([])->describeTarget());
    }

    private function createRequest(): DeliveryRequest
    {
        return new DeliveryRequest(ReportFixture::report(), 'https://example.com/typo3/report/CR', 2, 'delivery-uuid', static fn(): string => 'png-bytes');
    }

    /**
     * @param array<string, string> $webhookConfiguration
     */
    private function createDestination(array $webhookConfiguration): WebhookDestination
    {
        return new WebhookDestination(
            new SettingsProviderStub(['webhook' => array_merge(['enabled' => '1', 'url' => self::URL], $webhookConfiguration)]),
            new WebhookClient(new RequestFactory(new GuzzleClientFactory()), new ResponseReferenceExtractor(), new ExtensionInfo('1.2.3')),
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
