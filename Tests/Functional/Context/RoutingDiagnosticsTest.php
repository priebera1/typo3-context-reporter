<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Context;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\ContextDocumentBuilder;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Domain\ContextDocument;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * "context.routing" and "context.page.frontendUrl": facts about the website
 * address of the reported page or of the page of a reported record. The
 * fixture (routing.csv) adds below "About" [2] a page deleted in workspace A
 * [80, version 81], a spacer [83], an external link [84], a shortcut [85], a
 * page whose TSconfig disables the preview of standard pages [86] with a
 * subpage [87], a custom page type 201 [89] and a French element on a page
 * without French translation [82]; a root page of a site without host [88]
 * with an element [90]. Page 4 "Other root" belongs to no site.
 */
final class RoutingDiagnosticsTest extends AbstractContextReporterTestCase
{
    private const WORKSPACE_A = 1;
    /** May use English and German only */
    private const RUTH = 11;

    protected array $coreExtensionsToLoad = ['typo3/cms-filelist', 'typo3/cms-workspaces'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/workspaces.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/routing.csv');
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'https://www.example.com/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'navigationTitle' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'flag' => 'us'],
                ['languageId' => 1, 'title' => 'Deutsch', 'navigationTitle' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/', 'flag' => 'de', 'fallbackType' => 'fallback', 'fallbacks' => '0'],
                ['languageId' => 2, 'title' => 'Français', 'navigationTitle' => 'Français', 'locale' => 'fr_FR.UTF-8', 'base' => '/fr/', 'flag' => 'fr', 'enabled' => false],
            ],
        ]);
        $this->get(SiteWriter::class)->write('hostless', [
            'rootPageId' => 88,
            'base' => '/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'navigationTitle' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'flag' => 'us'],
            ],
        ]);
    }

    /**
     * TYPO3 finds no site for a page deleted in the workspace: its root line
     * ends with the deleted page.
     */
    #[Test]
    public function coreFindsNoSiteForAPageDeletedInTheWorkspace(): void
    {
        $this->loginInWorkspace(self::EDITOR, self::WORKSPACE_A);

        $this->expectException(SiteNotFoundException::class);
        $this->get(SiteFinder::class)->getSiteByPageId(80);
    }

    /**
     * The router does not check whether the page still exists in the
     * workspace: with the site of its parent page, a page deleted in the
     * workspace gets the address of the language root (the home page).
     */
    #[Test]
    public function coreRouterLinksAPageDeletedInTheWorkspaceToTheLanguageRoot(): void
    {
        $this->loginInWorkspace(self::EDITOR, self::WORKSPACE_A);

        $uri = $this->get(SiteFinder::class)->getSiteByPageId(2)->getRouter()->generateUri(80);

        self::assertSame('https://www.example.com/', (string)$uri);
    }

    /**
     * Disabled languages and missing page translations do not stop the router either.
     */
    #[Test]
    public function coreRouterLinksDisabledLanguagesAndMissingTranslations(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $uri = $this->get(SiteFinder::class)->getSiteByPageId(2)->getRouter()->generateUri(2, ['_language' => 2]);

        self::assertSame('https://www.example.com/fr/about', (string)$uri);
    }

    #[Test]
    public function regularPagesHaveAnAddressAndNoRoutingFacts(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $context = $this->context(['type' => 'page', 'uid' => 2]);

        self::assertSame('https://www.example.com/about', $context['page']['frontendUrl']);
        self::assertArrayNotHasKey('routing', $context);
    }

    #[Test]
    public function pagesWithoutSiteHaveNoAddress(): void
    {
        $this->loginBackendUser(self::ADMIN);

        foreach ([['type' => 'page', 'uid' => 4], ['type' => 'record', 'table' => 'tt_content', 'uid' => 11]] as $target) {
            $context = $this->context($target);
            self::assertSame(['notes' => ['noSite']], $context['routing']);
            self::assertArrayNotHasKey('frontendUrl', $context['page']);
        }
    }

    #[Test]
    public function previewabilityFollowsTheViewButtonOfTypo3(): void
    {
        $this->loginBackendUser(self::ADMIN);

        // Folders and spacers have no address of their own
        foreach ([3, 83] as $uid) {
            $context = $this->context(['type' => 'page', 'uid' => $uid]);
            self::assertSame(['notes' => ['notPreviewable']], $context['routing'], 'Page ' . $uid);
            self::assertArrayNotHasKey('frontendUrl', $context['page'], 'Page ' . $uid);
        }
        // TCEMAIN.preview.disableButtonForDokType of the page and its subpages
        foreach ([86, 87] as $uid) {
            self::assertSame(['notes' => ['notPreviewable']], $this->context(['type' => 'page', 'uid' => $uid])['routing'] ?? null, 'Page ' . $uid);
        }
        // External links, shortcuts and custom page types are previewable in TYPO3
        $expected = [
            84 => 'https://www.example.com/about/partner-website',
            85 => 'https://www.example.com/about/shortcut',
            89 => 'https://www.example.com/about/custom-type',
        ];
        foreach ($expected as $uid => $url) {
            $context = $this->context(['type' => 'page', 'uid' => $uid]);
            self::assertSame($url, $context['page']['frontendUrl'] ?? null, 'Page ' . $uid);
            self::assertArrayNotHasKey('routing', $context, 'Page ' . $uid);
        }
    }

    #[Test]
    public function pagesDeletedInTheWorkspaceGetNoAddress(): void
    {
        $this->loginInWorkspace(self::EDITOR, self::WORKSPACE_A);

        $context = $this->context(['type' => 'page', 'uid' => 80]);

        self::assertSame(['notes' => ['deletedInWorkspace']], $context['routing']);
        self::assertArrayNotHasKey('frontendUrl', $context['page']);
    }

    #[Test]
    public function pagesNewInTheWorkspaceKeepTheirAddressWithANote(): void
    {
        $this->loginInWorkspace(self::EDITOR, self::WORKSPACE_A);

        foreach ([['type' => 'page', 'uid' => 42], ['type' => 'record', 'table' => 'tt_content', 'uid' => 34]] as $target) {
            $context = $this->context($target);
            self::assertSame('https://www.example.com/about/new-a', $context['page']['frontendUrl']);
            self::assertSame(['notes' => ['newInWorkspace']], $context['routing']);
        }
    }

    #[Test]
    public function languageFactsExplainTheAddressOfATranslation(): void
    {
        $this->loginBackendUser(self::ADMIN);

        // German: page translated, falls back to English
        $german = $this->context(['type' => 'record', 'table' => 'tt_content', 'uid' => 12]);
        self::assertSame('https://www.example.com/de/about', $german['page']['frontendUrl']);
        self::assertSame(['fallback' => ['type' => 'fallback', 'languages' => [['id' => 0, 'title' => 'English']]]], $german['routing']);

        // French: disabled in the site configuration and the page is not translated
        $french = $this->context(['type' => 'record', 'table' => 'tt_content', 'uid' => 82]);
        self::assertSame('https://www.example.com/fr/about', $french['page']['frontendUrl']);
        self::assertSame(['notes' => ['languageDisabled', 'pageNotTranslated'], 'fallback' => ['type' => 'strict']], $french['routing']);

        // Language 5 does not exist in the site
        $unknown = $this->context(['type' => 'record', 'table' => 'tt_content', 'uid' => 91]);
        self::assertSame(['notes' => ['languageNotInSite']], $unknown['routing']);
        self::assertArrayNotHasKey('frontendUrl', $unknown['page']);
    }

    #[Test]
    public function translationsAreOnlyCheckedInLanguagesTheReporterMayUse(): void
    {
        // Ruth may use English and German only
        $this->loginBackendUser(self::RUTH);

        $french = $this->context(['type' => 'record', 'table' => 'tt_content', 'uid' => 82]);

        self::assertSame(['notes' => ['languageDisabled'], 'fallback' => ['type' => 'strict']], $french['routing']);
    }

    #[Test]
    public function fallbackLanguagesTheReporterMayNotUseAreListedWithoutTitle(): void
    {
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'https://www.example.com/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'navigationTitle' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'flag' => 'us'],
                ['languageId' => 1, 'title' => 'Deutsch', 'navigationTitle' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/', 'flag' => 'de', 'fallbackType' => 'fallback', 'fallbacks' => '2,0'],
                ['languageId' => 2, 'title' => 'Français', 'navigationTitle' => 'Français', 'locale' => 'fr_FR.UTF-8', 'base' => '/fr/', 'flag' => 'fr'],
            ],
        ]);
        $this->loginBackendUser(self::RUTH);

        self::assertSame(
            ['fallback' => ['type' => 'fallback', 'languages' => [['id' => 2], ['id' => 0, 'title' => 'English']]]],
            $this->context(['type' => 'record', 'table' => 'tt_content', 'uid' => 12])['routing'],
        );
    }

    #[Test]
    public function sitesWithoutHostAreNoted(): void
    {
        $this->loginBackendUser(self::ADMIN);

        foreach ([['type' => 'page', 'uid' => 88], ['type' => 'record', 'table' => 'tt_content', 'uid' => 90]] as $target) {
            $context = $this->context($target);
            self::assertSame('/', $context['page']['frontendUrl']);
            self::assertSame(['notes' => ['baseWithoutHost']], $context['routing']);
        }
    }

    #[Test]
    public function otherSubjectsHaveNoRoutingFacts(): void
    {
        $this->loginBackendUser(self::ADMIN);

        foreach ([
            ['source' => 'contextMenu', 'target' => ['type' => 'file', 'uid' => self::FILE_LOGO]],
            ['source' => 'toolbar', 'location' => ['url' => '/typo3/module/site/configuration', 'module' => 'site_configuration']],
        ] as $input) {
            self::assertArrayNotHasKey('routing', $this->build($input)->toArray()['context'] ?? []);
        }
    }

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private function context(array $target): array
    {
        return $this->build(['source' => 'contextMenu', 'target' => $target])->toArray()['context'] ?? [];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function build(array $input): ContextDocument
    {
        $request = CollectionRequest::fromArray($input, new BackendLocationFactory());
        return $this->get(ContextDocumentBuilder::class)->build($request, $GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST']);
    }

    private function loginInWorkspace(int $userUid, int $workspace): void
    {
        $backendUser = $this->loginBackendUser($userUid);
        $backendUser->setWorkspace($workspace);
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect($workspace));
    }
}
