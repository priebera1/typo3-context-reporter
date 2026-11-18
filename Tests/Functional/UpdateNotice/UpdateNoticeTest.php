<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\UpdateNotice;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\ExtensionInfo;
use Priebera\ContextReporter\Controller\ReportModuleController;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use Priebera\ContextReporter\Tests\Functional\Controller\ModuleRequestTrait;
use Priebera\ContextReporter\UpdateNotice\LatestReleaseProvider;
use Priebera\ContextReporter\UpdateNotice\SemanticVersion;
use Priebera\ContextReporter\UpdateNotice\UpdateNoticeDismissalStore;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Http\RouteDispatcher;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Exception;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Page\AssetCollector;

/**
 * "New Context Reporter version available" in System › Context Reports: who sees it, when, and how it is
 * dismissed through the real backend AJAX route (route token, POST only).
 */
final class UpdateNoticeTest extends AbstractContextReporterTestCase
{
    use ModuleRequestTrait;

    private const NOTICE = 'data-context-reporter-update-notice=';
    private const DISMISS_ROUTE = 'ajax_context_reporter_update_notice_dismiss';

    private int $releaseRequests = 0;

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['contextReporterTest']);
        parent::tearDown();
    }

    #[Test]
    public function administratorsSeeANewerReleaseWithTheInstalledVersionAndOneLink(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $newer = $this->newer();
        $this->cacheLatestRelease($newer);

        $html = $this->renderIndex();

        self::assertStringContainsString(self::NOTICE . '"' . $newer . '"', $html);
        self::assertStringContainsString('Context Reporter ' . $newer . ' is available.', $html);
        self::assertStringContainsString('You are using ' . $this->installed() . '.', $html);
        self::assertStringContainsString('href="https://github.com/priebera1/typo3-context-reporter/blob/' . $newer . '/CHANGELOG.md" target="_blank" rel="noopener noreferrer"', $html);
        self::assertStringContainsString('data-context-reporter-dismiss-update="' . $newer . '"', $html);
        self::assertContains('@priebera/context-reporter/update-notice.js', $this->get(AssetCollector::class)->getJavaScriptModules());
        self::assertSame(0, $this->releaseRequests);
    }

    #[Test]
    public function theSameOrAnOlderReleaseShowsNothing(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $installed = SemanticVersion::tryParse($this->installed());
        self::assertNotNull($installed);
        $older = $installed->patch > 0
            ? $installed->major . '.' . $installed->minor . '.' . ($installed->patch - 1)
            : $installed->major . '.' . max(0, $installed->minor - 1) . '.0';
        foreach ([$this->installed(), $older] as $latest) {
            $this->cacheLatestRelease($latest);
            $html = $this->renderIndex();
            self::assertStringNotContainsString(self::NOTICE, $html, $latest);
        }
    }

    #[Test]
    public function editorsWithTheModuleNeitherSeeTheNoticeNorCauseACheck(): void
    {
        $this->loginBackendUser(self::REVIEWER);
        $this->cacheLatestRelease($this->newer(), LatestReleaseProvider::CACHE_TTL * 2);
        $this->failReleaseRequests();

        self::assertStringNotContainsString(self::NOTICE, $this->renderIndex());
        self::assertSame(0, $this->releaseRequests);
    }

    #[Test]
    public function anUnreachableHubLeavesTheModuleWorkingAndIsAskedOncePerHour(): void
    {
        $this->loginBackendUser(self::ADMIN);
        // An expired cache: the next page load asks, the endpoint does not answer
        $this->cacheLatestRelease($this->newer(), LatestReleaseProvider::CACHE_TTL + 1);
        $this->failReleaseRequests();

        $html = $this->renderIndex();
        self::assertStringNotContainsString(self::NOTICE, $html);
        self::assertStringContainsString('Context Reports', $html);
        self::assertSame(1, $this->releaseRequests);

        $this->renderIndex();
        self::assertSame(1, $this->releaseRequests, 'The failure is cached');
    }

    #[Test]
    public function dismissingHidesThatReleaseForThisAdministratorUntilALaterOne(): void
    {
        $admin = $this->loginBackendUser(self::ADMIN);
        $newer = $this->newer();
        $this->cacheLatestRelease($newer);

        $response = $this->dismiss($newer);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['success' => true, 'version' => $newer], json_decode((string)$response->getBody(), true));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertTrue((new UpdateNoticeDismissalStore())->isDismissed($admin, SemanticVersion::tryParse($newer) ?? throw new \LogicException()));
        // Stored in the user's own configuration (be_users.uc), not only in memory
        $storedUc = (string)$this->getConnectionPool()->getConnectionForTable('be_users')
            ->select(['uc'], 'be_users', ['uid' => self::ADMIN])->fetchOne();
        self::assertStringContainsString($newer, $storedUc);

        self::assertStringNotContainsString(self::NOTICE, $this->renderIndex());

        $later = $this->newer(2);
        $this->cacheLatestRelease($later);
        self::assertStringContainsString(self::NOTICE . '"' . $later . '"', $this->renderIndex());
    }

    #[Test]
    public function editorsCannotDismissAndNothingIsStored(): void
    {
        $editor = $this->loginBackendUser(self::REVIEWER);
        $response = $this->dismiss($this->newer());
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['success' => false, 'code' => 'accessDenied'], json_decode((string)$response->getBody(), true));
        self::assertArrayNotHasKey(UpdateNoticeDismissalStore::UC_KEY, $editor->uc);
    }

    #[Test]
    public function onlyAStableVersionIsAccepted(): void
    {
        $admin = $this->loginBackendUser(self::ADMIN);
        foreach (['', 'dev-main', '0.5.0-beta.1', '0.5', '<script>'] as $version) {
            $response = $this->dismiss($version);
            self::assertSame(400, $response->getStatusCode(), $version);
        }
        self::assertArrayNotHasKey(UpdateNoticeDismissalStore::UC_KEY, $admin->uc);
    }

    #[Test]
    public function theDismissRouteNeedsTheRouteTokenAndPost(): void
    {
        $admin = $this->loginBackendUser(self::ADMIN);
        $newer = $this->newer();

        foreach (['' => 'missing', 'forged-token' => 'invalid'] as $token => $case) {
            try {
                $this->dismiss($newer, $token);
                self::fail('A request without a valid route token was dispatched (' . $case . ')');
            } catch (Exception $exception) {
                // MissingRequestTokenException / InvalidRequestTokenException
                self::assertStringContainsString('Invalid request for route', $exception->getMessage());
            }
        }
        self::assertArrayNotHasKey(UpdateNoticeDismissalStore::UC_KEY, $admin->uc);

        // A GET (e.g. a link or an image in a page) never reaches the controller. The router's exception class
        // moved in TYPO3 v14 (Backend\Routing\Exception → Core\Http\Error); its code is the same on both branches.
        try {
            $this->get(Router::class)->matchResult($this->createBackendRequest('/typo3/ajax/context-reporter/update-notice/dismiss', 'GET'));
            self::fail('The dismiss route accepted GET');
        } catch (\Throwable $exception) {
            self::assertSame(1612649842, $exception->getCode(), $exception::class . ': ' . $exception->getMessage());
        }
    }

    private function dismiss(string $version, ?string $token = null): ResponseInterface
    {
        $url = new Uri((string)$this->get(UriBuilder::class)->buildUriFromRoute(self::DISMISS_ROUTE));
        parse_str($url->getQuery(), $query);
        if ($token !== null) {
            $query['token'] = $token;
        }
        $request = $this->createBackendRequest($url->getPath());
        $route = $this->get(Router::class)->matchResult($request)->getRoute();
        self::assertInstanceOf(Route::class, $route);
        $request = $request
            ->withQueryParams(array_filter($query, static fn(mixed $value): bool => $value !== ''))
            ->withParsedBody(['version' => $version])
            ->withAttribute('route', $route);
        $GLOBALS['TYPO3_REQUEST'] = $request;
        return $this->get(RouteDispatcher::class)->dispatch($request);
    }

    private function renderIndex(): string
    {
        $request = $this->createModuleRequest('system_contextreports');
        $response = $this->get(ReportModuleController::class)->indexAction($request);
        self::assertSame(200, $response->getStatusCode());
        return (string)$response->getBody();
    }

    private function installed(): string
    {
        return $this->get(ExtensionInfo::class)->getVersion();
    }

    /**
     * A release after the installed version (minor + step), so the tests keep working after every release.
     */
    private function newer(int $step = 1): string
    {
        $installed = SemanticVersion::tryParse($this->installed());
        self::assertNotNull($installed, 'The test instance reports a stable installed version');
        return $installed->major . '.' . ($installed->minor + $step) . '.0';
    }

    private function failReleaseRequests(): void
    {
        $counter = &$this->releaseRequests;
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['contextReporterTest'] = static function (callable $next) use (&$counter): \Closure {
            return static function (RequestInterface $request) use (&$counter) {
                $counter++;
                return Create::rejectionFor(new ConnectException('Could not resolve host', $request));
            };
        };
    }
}
