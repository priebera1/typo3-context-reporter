<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\ContextDocumentBuilder;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Middleware\BackendModuleValidator;
use TYPO3\CMS\Backend\Middleware\SiteResolver;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * The language of a page report follows the language the reporter selected in
 * a page tree module. The selection is made through TYPO3's own module request
 * handling, so the stored data has the shape of the installed TYPO3 branch:
 * module data "language" in TYPO3 v13, a per-page user preference (and module
 * data "languages") in TYPO3 v14.
 */
final class SelectedLanguageTest extends AbstractContextReporterTestCase
{
    /** Registered by TYPO3 v14 in front of the module controllers of page tree modules */
    private const PAGE_CONTEXT_MIDDLEWARE = 'TYPO3\\CMS\\Backend\\Middleware\\PageContextInitialization';

    protected array $coreExtensionsToLoad = ['typo3/cms-filelist', 'typo3/cms-viewpage'];

    protected array $testExtensionsToLoad = [
        'priebera/typo3-context-reporter',
        __DIR__ . '/../Fixtures/Extensions/page_language_module',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Page 2 "About" is translated into German (page 6) and French (page 7), page 1 "Home" is not translated
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/page_translations.csv');
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'https://www.example.com/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'navigationTitle' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'flag' => 'us'],
                ['languageId' => 1, 'title' => 'Deutsch', 'navigationTitle' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/', 'flag' => 'de'],
                ['languageId' => 2, 'title' => 'Français', 'navigationTitle' => 'Français', 'locale' => 'fr_FR.UTF-8', 'base' => '/fr/', 'flag' => 'fr'],
            ],
        ]);
    }

    #[Test]
    public function pageModuleReportUsesTheSelectedTranslation(): void
    {
        $this->loginBackendUser(self::EDITOR);
        $this->selectLanguages('web_layout', 2, [1]);

        $data = $this->buildForModule('web_layout', 2);

        self::assertSame(['id' => 1, 'title' => 'Deutsch', 'locale' => 'de-DE'], $data['context']['language']);
        self::assertSame('https://www.example.com/de/about', $data['context']['page']['frontendUrl']);
        self::assertStringContainsString('· language Deutsch ·', $data['summary']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pageTreeModules(): array
    {
        return [
            'Page module' => ['web_layout'],
            'Preview module' => ['page_preview'],
            'Module that stores a language list (EXT:visual_editor)' => ['web_edit'],
        ];
    }

    #[Test]
    #[DataProvider('pageTreeModules')]
    public function pageTreeModulesReportTheSelectedTranslation(string $module): void
    {
        $this->loginBackendUser(self::ADMIN);
        $this->selectLanguages($module, 2, [2]);

        $data = $this->buildForModule($module, 2);

        self::assertSame(['id' => 2, 'title' => 'Français', 'locale' => 'fr-FR'], $data['context']['language']);
        self::assertSame('https://www.example.com/fr/a-propos', $data['context']['page']['frontendUrl']);
    }

    #[Test]
    #[DataProvider('pageTreeModules')]
    public function withoutSelectionTheDefaultLanguageIsReported(string $module): void
    {
        $this->loginBackendUser(self::ADMIN);

        $data = $this->buildForModule($module, 2);

        self::assertSame(['id' => 0, 'title' => 'English', 'locale' => 'en-US'], $data['context']['language']);
        self::assertSame('https://www.example.com/about', $data['context']['page']['frontendUrl']);
    }

    #[Test]
    public function defaultLanguageSelectionIsReportedAsDefaultLanguage(): void
    {
        $this->loginBackendUser(self::EDITOR);
        $this->selectLanguages('web_layout', 2, [1]);
        $this->selectLanguages('web_layout', 2, [0]);

        $data = $this->buildForModule('web_layout', 2);

        self::assertSame(0, $data['context']['language']['id']);
        self::assertSame('https://www.example.com/about', $data['context']['page']['frontendUrl']);
    }

    /**
     * Side by side views show several languages; like the Preview module of
     * TYPO3 v14, the report then uses the default language unless exactly one
     * translation is selected.
     */
    #[Test]
    public function severalSelectedTranslationsAreReportedAsDefaultLanguage(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $this->selectLanguages('web_edit', 2, [0, 1, 2]);

        $data = $this->buildForModule('web_edit', 2);

        self::assertSame(0, $data['context']['language']['id']);
        self::assertSame('https://www.example.com/about', $data['context']['page']['frontendUrl']);
    }

    #[Test]
    public function oneSelectedTranslationBesideTheDefaultLanguageIsReported(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $this->selectLanguages('web_edit', 2, [0, 1]);

        $data = $this->buildForModule('web_edit', 2);

        self::assertSame(1, $data['context']['language']['id']);
    }

    #[Test]
    public function languageWithoutPageTranslationIsReportedAsDefaultLanguage(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $this->selectLanguages('web_layout', 1, [1]);

        $data = $this->buildForModule('web_layout', 1);

        self::assertSame(0, $data['context']['language']['id']);
        self::assertSame('https://www.example.com/', $data['context']['page']['frontendUrl']);
    }

    #[Test]
    public function languageTheReporterMayNotUseIsReportedAsDefaultLanguage(): void
    {
        $backendUser = $this->loginBackendUser(self::EDITOR);
        $this->selectLanguages('web_layout', 2, [1]);
        $this->restrictLanguages($backendUser, '0,2');

        $data = $this->buildForModule('web_layout', 2);

        self::assertSame(0, $data['context']['language']['id']);
    }

    #[Test]
    public function selectionOfAnotherPageIsNotUsed(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $this->selectLanguages('web_layout', 2, [1]);

        // Home has no German translation, whatever the Page module keeps for "About"
        $data = $this->buildForModule('web_layout', 1);

        self::assertSame(0, $data['context']['language']['id']);
    }

    #[Test]
    public function contextMenuReportInAModuleWithoutPageTreeUsesTheDefaultLanguage(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $this->selectLanguages('web_layout', 2, [1]);

        $data = $this->build([
            'source' => 'contextMenu',
            'target' => ['type' => 'page', 'uid' => 2],
            'location' => ['url' => '/typo3/module/site/configuration', 'module' => 'site_configuration'],
        ]);

        self::assertSame(0, $data['context']['language']['id']);
    }

    #[Test]
    public function pageModuleButtonReportUsesTheSelectedTranslation(): void
    {
        $this->loginBackendUser(self::EDITOR);
        $this->selectLanguages('web_layout', 2, [1]);

        $data = $this->build([
            'source' => 'pageModule',
            'target' => ['type' => 'page', 'uid' => 2],
            'location' => ['url' => '/typo3/module/web/layout?id=2', 'module' => 'web_layout'],
        ]);

        self::assertSame(1, $data['context']['language']['id']);
        self::assertSame('https://www.example.com/de/about', $data['context']['page']['frontendUrl']);
    }

    #[Test]
    public function pageTranslationIsReportedInItsOwnLanguage(): void
    {
        $this->loginBackendUser(self::EDITOR);

        $data = $this->build([
            'source' => 'formEngine',
            'location' => ['url' => '/typo3/record/edit?edit[pages][6]=edit'],
        ]);

        self::assertSame(['type' => 'page', 'table' => 'pages', 'uid' => 6], array_intersect_key($data['subject'], ['type' => 1, 'table' => 1, 'uid' => 1]));
        self::assertSame(['id' => 1, 'title' => 'Deutsch', 'locale' => 'de-DE'], $data['context']['language']);
        self::assertSame('https://www.example.com/de/about', $data['context']['page']['frontendUrl']);
    }

    /**
     * Selects languages the way the language menu of the module does and lets
     * TYPO3 store the selection: BackendModuleValidator keeps module data
     * parameters the module declares, and on TYPO3 v14 the page context keeps
     * the selection per page. Nothing is written by the test itself.
     *
     * @param non-empty-list<int> $languageIds
     */
    private function selectLanguages(string $moduleIdentifier, int $pageUid, array $languageIds): void
    {
        $backendUser = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $backendUser);
        $module = $this->get(ModuleProvider::class)->getModule($moduleIdentifier, $backendUser);
        self::assertNotNull($module, 'Module ' . $moduleIdentifier . ' is not available.');

        $query = ['id' => (string)$pageUid];
        if (self::isTypo3V14() || array_key_exists('languages', $module->getDefaultModuleData())) {
            $query['languages'] = array_map(strval(...), $languageIds);
        } else {
            $query['language'] = (string)$languageIds[0];
        }

        $middlewares = [$this->get(BackendModuleValidator::class), $this->get(SiteResolver::class)];
        if (class_exists(self::PAGE_CONTEXT_MIDDLEWARE)) {
            $middlewares[] = $this->get(self::PAGE_CONTEXT_MIDDLEWARE);
        }
        $response = $this->createMiddlewareHandler($middlewares)->handle($this->createModuleRequest($moduleIdentifier, $query));
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @param list<mixed> $middlewares
     */
    private function createMiddlewareHandler(array $middlewares): RequestHandlerInterface
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };
        foreach (array_reverse($middlewares) as $middleware) {
            self::assertInstanceOf(MiddlewareInterface::class, $middleware);
            $handler = new class ($middleware, $handler) implements RequestHandlerInterface {
                public function __construct(
                    private readonly MiddlewareInterface $middleware,
                    private readonly RequestHandlerInterface $next,
                ) {}

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->middleware->process($request, $this->next);
                }
            };
        }
        return $handler;
    }

    /**
     * @param array<string, string|list<string>> $query
     */
    private function createModuleRequest(string $moduleIdentifier, array $query): ServerRequestInterface
    {
        $router = $this->get(Router::class);
        $request = $this->createBackendRequest('/typo3' . $this->getModulePath($moduleIdentifier), 'GET');
        $route = $router->matchResult($request)->getRoute();
        self::assertInstanceOf(Route::class, $route);
        return $request
            ->withQueryParams($query)
            ->withAttribute('route', $route)
            ->withAttribute('backend.user', $GLOBALS['BE_USER']);
    }

    private function getModulePath(string $moduleIdentifier): string
    {
        $path = $this->get(Router::class)->getRoute($moduleIdentifier)?->getPath();
        self::assertIsString($path);
        return $path;
    }

    private function restrictLanguages(BackendUserAuthentication $backendUser, string $languageIds): void
    {
        $backendUser->groupData['allowed_languages'] = $languageIds;
    }

    /**
     * A report started from the toolbar while the module shows the page.
     *
     * @return array<string, mixed>
     */
    private function buildForModule(string $moduleIdentifier, int $pageUid): array
    {
        return $this->build([
            'source' => 'toolbar',
            'location' => ['url' => '/typo3' . $this->getModulePath($moduleIdentifier) . '?id=' . $pageUid, 'module' => $moduleIdentifier],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function build(array $input): array
    {
        $request = CollectionRequest::fromArray($input, new BackendLocationFactory());
        return $this->get(ContextDocumentBuilder::class)->build($request, $GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'])->toArray();
    }

    private static function isTypo3V14(): bool
    {
        return (new Typo3Version())->getMajorVersion() >= 14;
    }
}
