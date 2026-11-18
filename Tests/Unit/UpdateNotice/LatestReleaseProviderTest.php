<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\UpdateNotice;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\SettingsRepository;
use Priebera\ContextReporter\Delivery\Http\BoundedHttpClient;
use Priebera\ContextReporter\Tests\Unit\Fixtures\InMemoryRegistry;
use Priebera\ContextReporter\UpdateNotice\LatestReleaseProvider;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class LatestReleaseProviderTest extends UnitTestCase
{
    private const NOW = 1_800_000_000;

    /**
     * @var list<array{request: RequestInterface, options: array<string, mixed>}>
     */
    private array $sentRequests = [];
    private InMemoryRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new InMemoryRegistry();
        putenv(LatestReleaseProvider::ENDPOINT_ENVIRONMENT_VARIABLE);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']);
        putenv(LatestReleaseProvider::ENDPOINT_ENVIRONMENT_VARIABLE);
        parent::tearDown();
    }

    #[Test]
    public function readsTheReleaseWithAPlainGetToTheFixedEndpoint(): void
    {
        $this->respondWith(self::answer('0.5.0'));

        $release = $this->provider()->getLatestRelease();

        self::assertSame('0.5.0', $release?->version->toString());
        self::assertSame('2027-01-15', $release->releasedAt);
        self::assertCount(1, $this->sentRequests);
        $request = $this->sentRequests[0]['request'];
        self::assertSame('GET', $request->getMethod());
        self::assertSame(LatestReleaseProvider::ENDPOINT, (string)$request->getUri());
        self::assertSame('', $request->getUri()->getQuery());
        self::assertSame('', (string)$request->getBody());
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        // Nothing about the installation: no cookie, authorization or Context Reporter version
        self::assertFalse($request->hasHeader('Authorization'));
        self::assertFalse($request->hasHeader('Cookie'));
        self::assertStringNotContainsString('Context Reporter', $request->getHeaderLine('User-Agent'));
        $options = $this->sentRequests[0]['options'];
        self::assertFalse($options['allow_redirects']);
        self::assertSame(LatestReleaseProvider::TIMEOUT_SECONDS, $options['timeout']);
        self::assertSame(LatestReleaseProvider::TIMEOUT_SECONDS, $options['connect_timeout']);
    }

    #[Test]
    public function aReleaseIsCachedForTwelveHours(): void
    {
        $this->respondWith(self::answer('0.5.0'));
        self::assertSame('0.5.0', $this->provider(self::NOW)->getLatestRelease()?->version->toString());
        self::assertSame(
            ['checkedAt' => self::NOW, 'release' => ['version' => '0.5.0', 'releasedAt' => '2027-01-15', 'releaseUrl' => 'https://github.com/priebera1/typo3-context-reporter/blob/0.5.0/CHANGELOG.md']],
            $this->registry->values[SettingsRepository::REGISTRY_NAMESPACE][LatestReleaseProvider::REGISTRY_KEY],
        );

        $this->respondWith(self::answer('0.6.0'));
        self::assertSame('0.5.0', $this->provider(self::NOW + LatestReleaseProvider::CACHE_TTL - 1)->getLatestRelease()?->version->toString());
        self::assertCount(1, $this->sentRequests);

        self::assertSame('0.6.0', $this->provider(self::NOW + LatestReleaseProvider::CACHE_TTL)->getLatestRelease()?->version->toString());
        self::assertCount(2, $this->sentRequests);
    }

    /**
     * @return iterable<string, array{Response|\Throwable}>
     */
    public static function failures(): iterable
    {
        yield 'network / DNS' => [new ConnectException('Could not resolve host', new Request('GET', LatestReleaseProvider::ENDPOINT))];
        yield 'server error' => [new Response(503, ['Content-Type' => 'application/json'], '{"error":{"code":"release_info_unavailable"}}')];
        yield 'not found' => [new Response(404, [], 'Not found')];
        yield 'redirect' => [new Response(302, ['Location' => 'https://evil.example/latest'])];
        yield 'empty' => [new Response(200, ['Content-Type' => 'application/json'], '')];
        yield 'not JSON' => [new Response(200, ['Content-Type' => 'text/html'], '<html>maintenance</html>')];
        yield 'other schema' => [new Response(200, [], '{"schema":"context-reporter.release.v2","release":{"version":"0.5.0"}}')];
        yield 'no schema (AQG shape)' => [new Response(200, [], '{"success":true,"release":{"version":"0.5.0"}}')];
        yield 'pre-release' => [new Response(200, [], '{"schema":"context-reporter.release.v1","release":{"version":"0.5.0-beta.1"}}')];
        yield 'too large' => [new Response(200, [], '{"schema":"context-reporter.release.v1","release":{"version":"0.5.0"},"pad":"' . str_repeat('x', LatestReleaseProvider::MAX_RESPONSE_BYTES) . '"}')];
        yield 'too deep' => [new Response(200, [], '{"schema":"context-reporter.release.v1","release":' . str_repeat('[', 20) . str_repeat(']', 20) . '}')];
    }

    #[Test]
    #[DataProvider('failures')]
    public function failuresAreSilentAndCachedForOneHour(Response|\Throwable $failure): void
    {
        $this->respondWith($failure);
        self::assertNull($this->provider(self::NOW)->getLatestRelease());
        self::assertNull($this->registry->values[SettingsRepository::REGISTRY_NAMESPACE][LatestReleaseProvider::REGISTRY_KEY]['release']);

        // Within the hour: no further request, even if the endpoint is back
        $this->respondWith(self::answer('0.5.0'));
        self::assertNull($this->provider(self::NOW + LatestReleaseProvider::FAILURE_CACHE_TTL - 1)->getLatestRelease());
        self::assertCount(1, $this->sentRequests);

        self::assertSame('0.5.0', $this->provider(self::NOW + LatestReleaseProvider::FAILURE_CACHE_TTL)->getLatestRelease()?->version->toString());
        self::assertCount(2, $this->sentRequests);
    }

    #[Test]
    public function aCheckFromTheFutureIsNotTrusted(): void
    {
        $this->registry->set(SettingsRepository::REGISTRY_NAMESPACE, LatestReleaseProvider::REGISTRY_KEY, ['checkedAt' => self::NOW + 86400, 'release' => ['version' => '9.9.9']]);
        $this->respondWith(self::answer('0.5.0'));
        self::assertSame('0.5.0', $this->provider(self::NOW)->getLatestRelease()?->version->toString());
    }

    #[Test]
    public function brokenCacheEntriesAreRefreshed(): void
    {
        foreach (['x', ['release' => ['version' => '9.9.9']], ['checkedAt' => '1800000000', 'release' => null]] as $entry) {
            $this->registry->set(SettingsRepository::REGISTRY_NAMESPACE, LatestReleaseProvider::REGISTRY_KEY, $entry);
            $this->respondWith(self::answer('0.5.0'));
            self::assertSame('0.5.0', $this->provider()->getLatestRelease()?->version->toString());
        }
    }

    #[Test]
    public function anUnavailableRegistryDoesNotBreakTheCheck(): void
    {
        $this->registry->failing = true;
        $this->respondWith(self::answer('0.5.0'));
        self::assertSame('0.5.0', $this->provider()->getLatestRelease()?->version->toString());
    }

    /**
     * @return iterable<string, array{string|false, string}>
     */
    public static function endpointOverrides(): iterable
    {
        yield 'unset' => [false, LatestReleaseProvider::ENDPOINT];
        yield 'empty' => ['  ', LatestReleaseProvider::ENDPOINT];
        yield 'https staging' => ['https://staging.example.com/api/extension/v1/releases/latest', 'https://staging.example.com/api/extension/v1/releases/latest'];
        yield 'http local' => ['http://localhost:3210/api/extension/v1/releases/latest', 'http://localhost:3210/api/extension/v1/releases/latest'];
        yield 'http docker host' => ['http://host.docker.internal:3210/x', 'http://host.docker.internal:3210/x'];
        yield 'http .test' => ['http://hub.test/x', 'http://hub.test/x'];
        yield 'http public host' => ['http://hub.example.com/x', LatestReleaseProvider::ENDPOINT];
        yield 'credentials' => ['https://user:pw@hub.example.com/x', LatestReleaseProvider::ENDPOINT];
        yield 'query' => ['https://hub.example.com/x?site=1', LatestReleaseProvider::ENDPOINT];
        yield 'file' => ['file:///etc/passwd', LatestReleaseProvider::ENDPOINT];
        yield 'gopher' => ['gopher://localhost/x', LatestReleaseProvider::ENDPOINT];
        yield 'switched off' => [' OFF ', ''];
    }

    #[Test]
    #[DataProvider('endpointOverrides')]
    public function onlyTheEnvironmentCanReplaceTheEndpoint(string|false $override, string $expected): void
    {
        self::assertSame($expected, LatestReleaseProvider::resolveEndpoint($override));
    }

    #[Test]
    public function theEnvironmentOverrideIsUsed(): void
    {
        putenv(LatestReleaseProvider::ENDPOINT_ENVIRONMENT_VARIABLE . '=http://localhost:3210/api/extension/v1/releases/latest');
        $this->respondWith(self::answer('0.5.0'));
        $this->provider()->getLatestRelease();
        self::assertSame('http://localhost:3210/api/extension/v1/releases/latest', (string)$this->sentRequests[0]['request']->getUri());
    }

    #[Test]
    public function aSwitchedOffCheckAsksNothingAndCachesNothing(): void
    {
        putenv(LatestReleaseProvider::ENDPOINT_ENVIRONMENT_VARIABLE . '=off');
        $this->registry->set(SettingsRepository::REGISTRY_NAMESPACE, LatestReleaseProvider::REGISTRY_KEY, ['checkedAt' => self::NOW, 'release' => ['version' => '0.5.0']]);
        $this->respondWith(self::answer('0.5.0'));
        self::assertNull($this->provider()->getLatestRelease());
        self::assertSame([], $this->sentRequests);
    }

    private static function answer(string $version): Response
    {
        return new Response(200, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'public, max-age=3600'], (string)json_encode([
            'schema' => 'context-reporter.release.v1',
            'release' => [
                'product' => 'context-reporter',
                'channel' => 'stable',
                'version' => $version,
                'releasedAt' => '2027-01-15',
                'releaseUrl' => 'https://github.com/priebera1/typo3-context-reporter/blob/' . $version . '/CHANGELOG.md',
            ],
        ]));
    }

    private function provider(int $now = self::NOW): LatestReleaseProvider
    {
        $context = new Context();
        $context->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('@' . $now)));
        return new LatestReleaseProvider(new BoundedHttpClient(new RequestFactory(new GuzzleClientFactory())), $this->registry, $context, new NullLogger());
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
                        if ($result instanceof \Throwable) {
                            return Create::rejectionFor($result);
                        }
                        // Like curl: the headers callback, then the body into the sink
                        if (isset($options['on_headers'])) {
                            $options['on_headers']($result);
                        }
                        return Create::promiseFor($result);
                    };
                },
            ],
        ];
    }
}
