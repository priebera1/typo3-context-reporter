<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\UpdateNotice;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\ExtensionInfo;
use Priebera\ContextReporter\Configuration\SettingsRepository;
use Priebera\ContextReporter\Delivery\Http\BoundedHttpClient;
use Priebera\ContextReporter\Tests\Unit\Fixtures\BackendUserStub;
use Priebera\ContextReporter\Tests\Unit\Fixtures\InMemoryRegistry;
use Priebera\ContextReporter\UpdateNotice\LatestReleaseProvider;
use Priebera\ContextReporter\UpdateNotice\SemanticVersion;
use Priebera\ContextReporter\UpdateNotice\UpdateNoticeDismissalStore;
use Priebera\ContextReporter\UpdateNotice\UpdateNoticeService;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class UpdateNoticeServiceTest extends UnitTestCase
{
    private const NOW = 1_800_000_000;

    private InMemoryRegistry $registry;
    private int $requests = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new InMemoryRegistry();
        // Any request that still goes out fails and is counted: these tests run from the cached answer
        $requests = &$this->requests;
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = ['verify' => true, 'handler' => ['count' => static function (callable $next) use (&$requests): \Closure {
            return static function () use (&$requests) {
                $requests++;
                return \GuzzleHttp\Promise\Create::rejectionFor(new \RuntimeException('offline'));
            };
        }]];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string|null, bool}>
     */
    public static function versions(): iterable
    {
        yield 'same version' => ['0.4.0', '0.4.0', false];
        yield 'newer release' => ['0.4.0', '0.5.0', true];
        yield 'newer patch' => ['0.4.0', '0.4.1', true];
        yield 'installed is newer (unreleased 0.4.0, Hub advertises 0.3.0)' => ['0.4.0', '0.3.0', false];
        yield 'no release known' => ['0.4.0', null, false];
        yield 'development installation' => ['dev-main', '0.5.0', false];
        yield 'unknown installed version' => ['', '0.5.0', false];
    }

    #[Test]
    #[DataProvider('versions')]
    public function onlyANewerStableReleaseShowsTheNotice(string $installed, ?string $latest, bool $shown): void
    {
        $this->cache($latest);
        $notice = $this->service($installed)->buildFor(BackendUserStub::create(1, true));
        if (!$shown) {
            self::assertNull($notice);
            return;
        }
        self::assertSame([
            'version' => $latest,
            'installedVersion' => $installed,
            'releasedAt' => '2027-01-15',
            'releaseUrl' => 'https://github.com/priebera1/typo3-context-reporter/blob/' . $latest . '/CHANGELOG.md',
        ], $notice);
    }

    #[Test]
    public function aDismissedReleaseStaysHiddenUntilALaterOneAppears(): void
    {
        $admin = BackendUserStub::create(1, true);
        $this->cache('0.5.0');
        self::assertSame('0.5.0', $this->service()->buildFor($admin)['version'] ?? null);

        (new UpdateNoticeDismissalStore())->dismiss($admin, SemanticVersion::tryParse('0.5.0') ?? throw new \LogicException());
        self::assertNull($this->service()->buildFor($admin));
        // Another administrator still sees it
        self::assertSame('0.5.0', $this->service()->buildFor(BackendUserStub::create(2, true))['version'] ?? null);

        foreach (['0.5.1', '0.6.0'] as $later) {
            $this->cache($later);
            self::assertSame($later, $this->service()->buildFor($admin)['version'] ?? null);
        }
    }

    #[Test]
    public function editorsNeitherSeeTheNoticeNorTriggerACheck(): void
    {
        $service = $this->service();
        foreach ([BackendUserStub::create(5, false), BackendUserStub::create(0, true), null, 'admin'] as $user) {
            self::assertFalse($service->canSeeUpdateNotices($user));
            self::assertNull($service->buildFor($user));
        }
        self::assertSame(0, $this->requests);
        self::assertSame([], $this->registry->values);
    }

    #[Test]
    public function anUnreachableEndpointMeansNoNotice(): void
    {
        self::assertNull($this->service()->buildFor(BackendUserStub::create(1, true)));
        self::assertSame(1, $this->requests);
        // Cached failure: the next page load does not wait again
        self::assertNull($this->service()->buildFor(BackendUserStub::create(1, true)));
        self::assertSame(1, $this->requests);
    }

    #[Test]
    public function aBrokenRegistryMeansNoNoticeNotAnError(): void
    {
        $this->registry->failing = true;
        self::assertNull($this->service()->buildFor(BackendUserStub::create(1, true)));
    }

    private function cache(?string $version): void
    {
        $this->registry->values[SettingsRepository::REGISTRY_NAMESPACE][LatestReleaseProvider::REGISTRY_KEY] = [
            'checkedAt' => self::NOW,
            'release' => $version === null ? null : [
                'version' => $version,
                'releasedAt' => '2027-01-15',
                'releaseUrl' => 'https://github.com/priebera1/typo3-context-reporter/blob/' . $version . '/CHANGELOG.md',
            ],
        ];
    }

    private function service(string $installed = '0.4.0'): UpdateNoticeService
    {
        $context = new Context();
        $context->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('@' . self::NOW)));
        return new UpdateNoticeService(
            new LatestReleaseProvider(new BoundedHttpClient(new RequestFactory(new GuzzleClientFactory())), $this->registry, $context, new NullLogger()),
            new UpdateNoticeDismissalStore(),
            new ExtensionInfo($installed),
        );
    }
}
