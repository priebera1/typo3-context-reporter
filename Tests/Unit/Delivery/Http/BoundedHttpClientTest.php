<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Delivery\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\ExtensionInfo;
use Priebera\ContextReporter\Configuration\HubIntake;
use Priebera\ContextReporter\Delivery\DeliveryRequest;
use Priebera\ContextReporter\Delivery\Destination\HubDestination;
use Priebera\ContextReporter\Delivery\Destination\WebhookDestination;
use Priebera\ContextReporter\Delivery\Http\BoundedHttpClient;
use Priebera\ContextReporter\Delivery\Http\HttpExchange;
use Priebera\ContextReporter\Delivery\Webhook\ResponseReferenceExtractor;
use Priebera\ContextReporter\Delivery\Webhook\WebhookClient;
use Priebera\ContextReporter\Delivery\Webhook\WebhookReportSender;
use Priebera\ContextReporter\Export\JsonReportExporter;
use Priebera\ContextReporter\Export\ReportPayloadFactory;
use Priebera\ContextReporter\Tests\Unit\Fixtures\ReportFixture;
use Priebera\ContextReporter\Tests\Unit\Fixtures\SettingsProviderStub;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * K2/K3: answers on a real socket (Tests/Unit/Fixtures/Http/scripted-server.php):
 * bodies in several chunks are read completely, a trickling or silent server
 * cannot outlast the total deadline, and too large or cut-off answers fail safely.
 */
final class BoundedHttpClientTest extends UnitTestCase
{
    private const TOKEN = 's_demoWebsiteLocalDev000001';
    private const SECRET = 'whsec_localDevSecretDemoWebsite0000000001';

    /**
     * @var resource|null
     */
    private $server;

    /**
     * @var array<int, resource>
     */
    private array $pipes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = ['verify' => true];
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server, 9);
            foreach ($this->pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($this->server);
        }
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function bodiesInPieces(): iterable
    {
        yield 'chunked transfer encoding' => ['chunked', 200];
        yield 'Content-Length, sent in pieces' => ['pieces', 201];
    }

    #[Test]
    #[DataProvider('bodiesInPieces')]
    public function aBodyInSeveralPiecesIsReadCompletely(string $scenario, int $status): void
    {
        $base = $this->startServer($scenario);

        $exchange = $this->createClient()->request('POST', $base . '/hook', [], '{}', 5.0);

        self::assertSame($status, $exchange->status);
        self::assertTrue($exchange->isBodyComplete());
        self::assertSame(['reference' => 'LW-152', 'url' => $base . '/app/reports/LW-152'], json_decode($exchange->body, true, 4, JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tricklingBodies(): iterable
    {
        yield 'Content-Length' => ['trickle'];
        yield 'chunked' => ['trickleChunked'];
    }

    #[Test]
    #[DataProvider('tricklingBodies')]
    public function aTricklingBodyCannotOutlastTheTotalDeadline(string $scenario): void
    {
        $base = $this->startServer($scenario);

        $started = microtime(true);
        $exchange = $this->createClient()->request('POST', $base . '/hook', [], '{}', 1.0);
        $elapsed = microtime(true) - $started;

        self::assertLessThan(2.5, $elapsed, 'Each byte arrives within any read timeout; only a total deadline stops it');
        self::assertSame(200, $exchange->status);
        self::assertSame(HttpExchange::PROBLEM_INCOMPLETE, $exchange->problem);
        self::assertSame('', $exchange->body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function serversWithoutAnswer(): iterable
    {
        yield 'headers one byte at a time' => ['slowHeaders'];
        yield 'silent' => ['silent'];
    }

    #[Test]
    #[DataProvider('serversWithoutAnswer')]
    public function noAnswerWithinTheDeadlineIsAFailureWithoutTheUrl(string $scenario): void
    {
        $base = $this->startServer($scenario);
        $url = $base . '/in/' . self::TOKEN;

        $started = microtime(true);
        $exchange = $this->createClient()->request('POST', $url, [], '{}', 1.0, [$url, self::TOKEN]);
        $elapsed = microtime(true) - $started;

        self::assertLessThan(2.5, $elapsed);
        self::assertNull($exchange->status);
        self::assertNotSame('', $exchange->message);
        self::assertStringNotContainsString(self::TOKEN, $exchange->message);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tooLargeAnswers(): iterable
    {
        yield 'declared' => ['largeDeclared'];
        yield 'chunked without length' => ['largeChunked'];
    }

    #[Test]
    #[DataProvider('tooLargeAnswers')]
    public function tooLargeAnswersAreNotRead(string $scenario): void
    {
        $base = $this->startServer($scenario);

        $exchange = $this->createClient()->request('POST', $base . '/hook', [], '{}', 5.0);

        self::assertSame(200, $exchange->status);
        self::assertSame(HttpExchange::PROBLEM_TOO_LARGE, $exchange->problem);
        self::assertSame('', $exchange->body);
    }

    #[Test]
    public function aCutOffBodyIsIncomplete(): void
    {
        $base = $this->startServer('truncated');

        $exchange = $this->createClient()->request('POST', $base . '/hook', [], '{}', 5.0);

        self::assertSame(200, $exchange->status);
        self::assertSame(HttpExchange::PROBLEM_INCOMPLETE, $exchange->problem);
    }

    #[Test]
    public function theHubKeepsReferenceAndLinkOfAChunkedAcknowledgement(): void
    {
        $base = $this->startServer('chunked');

        $outcome = $this->createHubDestination($base)->deliver($this->createRequest());

        self::assertTrue($outcome->successful, $outcome->message);
        self::assertSame('LW-152', $outcome->externalReference);
        self::assertSame($base . '/app/reports/LW-152', $outcome->externalUrl);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusableHubAcknowledgements(): iterable
    {
        yield 'cut off' => ['truncated', 'did not arrive completely'];
        yield 'too large' => ['largeChunked', 'larger than 64 KB'];
        yield 'malformed' => ['malformed', 'no Hub reference'];
    }

    #[Test]
    #[DataProvider('unusableHubAcknowledgements')]
    public function theHubFailsSafelyWithoutAUsableAcknowledgement(string $scenario, string $message): void
    {
        $base = $this->startServer($scenario);

        $outcome = $this->createHubDestination($base)->deliver($this->createRequest());

        self::assertFalse($outcome->successful);
        self::assertSame(200, $outcome->responseCode);
        self::assertStringContainsString($message, $outcome->message);
        self::assertSame('', $outcome->externalReference);
        self::assertStringNotContainsString(self::TOKEN, $outcome->message);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unhealthyHubs(): iterable
    {
        yield 'silent' => ['silent'];
        yield 'trickling body' => ['trickle'];
        yield 'headers one byte at a time' => ['slowHeaders'];
    }

    /**
     * The reporter waits for the Hub after "Send report" and the email follows
     * it, so a Hub that does not answer fails within the Hub's time limit.
     */
    #[Test]
    #[DataProvider('unhealthyHubs')]
    public function anUnhealthyHubFailsWithinTheInteractiveTimeLimit(string $scenario): void
    {
        self::assertLessThanOrEqual(5, HubIntake::TIMEOUT_SECONDS);
        $base = $this->startServer($scenario);

        $started = microtime(true);
        $outcome = $this->createHubDestination($base)->deliver($this->createRequest());
        $elapsed = microtime(true) - $started;

        self::assertLessThan(HubIntake::TIMEOUT_SECONDS + 1.0, $elapsed);
        self::assertFalse($outcome->successful);
        self::assertSame('', $outcome->externalReference);
        self::assertStringNotContainsString(self::TOKEN, $outcome->message);
    }

    /**
     * Whether a request without answer may have been received is taken from what the transfer did (the whole body was
     * sent), not from the error text: a silent receiver got the request, an unreachable one did not.
     */
    #[Test]
    public function aSilentReceiverMayHaveTheRequestAnUnreachableOneHasNot(): void
    {
        $base = $this->startServer('silent');
        $silent = $this->createClient()->request('POST', $base . '/in/' . self::TOKEN, ['Content-Type' => 'application/json'], '{"event":"report.created"}', 1.0);
        self::assertFalse($silent->hasStatus());
        self::assertTrue($silent->mayHaveArrived, $silent->message);

        $refused = $this->createClient()->request('POST', 'http://127.0.0.1:1/in/' . self::TOKEN, [], '{"event":"report.created"}', 1.0);
        self::assertFalse($refused->hasStatus());
        self::assertFalse($refused->mayHaveArrived, $refused->message);

        // Not routable: the connection itself times out (or is refused at once); nothing was sent
        $unreachable = $this->createClient()->request('POST', 'http://10.255.255.1/in/' . self::TOKEN, [], '{"event":"report.created"}', 1.0);
        self::assertFalse($unreachable->hasStatus());
        self::assertFalse($unreachable->mayHaveArrived, $unreachable->message);
    }

    #[Test]
    public function theGenericWebhookStaysDeliveredWhenItsAnswerTrickles(): void
    {
        $base = $this->startServer('trickle');
        $destination = new WebhookDestination(
            new SettingsProviderStub(['webhook' => ['enabled' => '1', 'url' => $base . '/hook', 'allowInsecureHttp' => '1', 'timeout' => '1']]),
            $this->createSender(),
        );

        $started = microtime(true);
        $outcome = $destination->deliver($this->createRequest());

        self::assertLessThan(2.5, microtime(true) - $started);
        self::assertTrue($outcome->successful);
        self::assertSame('HTTP 200 (the answer did not arrive completely in time)', $outcome->message);
        self::assertSame('', $outcome->externalReference);
    }

    private function startServer(string $scenario): string
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/../../Fixtures/Http/scripted-server.php', $scenario], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $this->server = $process;
        $this->pipes = $pipes;
        $port = trim((string)fgets($pipes[1]));
        if (preg_match('/^\d+$/D', $port) !== 1) {
            self::fail('The test server did not start: ' . stream_get_contents($pipes[2]));
        }
        return 'http://127.0.0.1:' . $port;
    }

    private function createClient(): BoundedHttpClient
    {
        return new BoundedHttpClient(new RequestFactory(new GuzzleClientFactory()));
    }

    private function createHubDestination(string $base): HubDestination
    {
        return new HubDestination(
            new SettingsProviderStub(['hub' => ['enabled' => '1', 'url' => $base . '/in/' . self::TOKEN, 'secret' => self::SECRET, 'allowInsecureHttp' => '1']]),
            $this->createSender(),
        );
    }

    private function createSender(): WebhookReportSender
    {
        return new WebhookReportSender(
            new WebhookClient($this->createClient(), new ResponseReferenceExtractor(), new ExtensionInfo('1.2.3')),
            new ReportPayloadFactory(new ExtensionInfo('1.2.3')),
            new JsonReportExporter(),
        );
    }

    private function createRequest(): DeliveryRequest
    {
        return new DeliveryRequest(ReportFixture::report(), 'https://example.com/typo3/report/CR', 1, 'delivery-uuid', static fn(): string => 'png-bytes');
    }
}
