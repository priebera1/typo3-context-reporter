<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Context;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\ContextDocumentBuilder;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Domain\ContextDocument;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Page\PageLayoutResolver;

/**
 * "context.placement": the content column of a reported content element as
 * the backend layout of its page defines it, the backend layout and where it
 * comes from, and "Show content from page" in both directions.
 *
 * Page TSconfig of "Home" [1] defines the layouts "two" (Main 0, Sidebar 5)
 * and "single" (Content 0); the backend layout record [1] has Stage 0 and
 * Footer 7. The fixture (placement.csv) adds below "About" [2]: a section
 * passing "two" on to its subpages [30, in workspace A "single": 38] with a
 * page [31], pages with "single" [32], "none" [33] and the record layout [39],
 * and pages showing the content of "About" [34], of the other root [35] and of
 * a removed page [36]; below "Other root" [4] another page showing the
 * content of "About" [37]. The fixture extension "container_columns" offers a
 * container column 200 to elements with the header "Container child".
 */
final class PlacementDiagnosticsTest extends AbstractContextReporterTestCase
{
    private const WORKSPACE_A = 1;
    /** May read the backend layout fields */
    private const LARS = 13;
    /** May read "Backend layout (this page only)", but not the one for subpages */
    private const OLGA = 14;

    private const LAYOUTS = <<<'TSCONFIG'
        mod.web_layout.BackendLayouts {
          two {
            title = Two columns
            config.backend_layout {
              colCount = 2
              rowCount = 1
              rows.1.columns {
                1 {
                  name = Main
                  colPos = 0
                }
                2 {
                  name = Sidebar
                  colPos = 5
                }
              }
            }
          }
          single {
            title = Single column
            config.backend_layout {
              colCount = 1
              rowCount = 1
              rows.1.columns.1 {
                name = Content
                colPos = 0
              }
            }
          }
        }
        TSCONFIG;

    protected array $coreExtensionsToLoad = ['typo3/cms-filelist', 'typo3/cms-workspaces'];

    protected array $testExtensionsToLoad = [
        'priebera/typo3-context-reporter',
        __DIR__ . '/../Fixtures/Extensions/container_columns',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/workspaces.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/placement.csv');
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['TSconfig' => self::LAYOUTS], ['uid' => 1]);
    }

    #[Test]
    public function columnsAreTheColumnsOfTheEffectiveBackendLayout(): void
    {
        $this->loginBackendUser(self::ADMIN);

        self::assertSame(
            ['colPos' => 5, 'label' => 'Sidebar', 'inBackendLayout' => true],
            $this->placement(['type' => 'record', 'table' => 'tt_content', 'uid' => 70])['column'] ?? null,
        );
        self::assertSame(
            ['colPos' => 7, 'label' => 'Footer', 'inBackendLayout' => true],
            $this->placement(['type' => 'record', 'table' => 'tt_content', 'uid' => 75])['column'] ?? null,
        );
        // The default layout of TYPO3 has one column
        self::assertSame(
            ['colPos' => 0, 'label' => 'Normal', 'inBackendLayout' => true],
            $this->placement(['type' => 'record', 'table' => 'tt_content', 'uid' => 10])['column'] ?? null,
        );
    }

    #[Test]
    public function columnsTheLayoutDoesNotDefineAreReportedWithTheColumnsOfTheLayout(): void
    {
        $this->loginBackendUser(self::EDITOR);

        $placement = $this->placement(['type' => 'record', 'table' => 'tt_content', 'uid' => 71]);

        self::assertSame(['colPos' => 3, 'inBackendLayout' => false], $placement['column'] ?? null);
        self::assertSame([['colPos' => 0, 'label' => 'Main'], ['colPos' => 5, 'label' => 'Sidebar']], $placement['layoutColumns'] ?? null);
    }

    #[Test]
    public function columnsOfferedByExtensionsCount(): void
    {
        $this->loginBackendUser(self::EDITOR);

        self::assertSame(
            ['colPos' => 200, 'label' => 'Container column', 'inBackendLayout' => true],
            $this->placement(['type' => 'record', 'table' => 'tt_content', 'uid' => 73])['column'] ?? null,
        );
        // The extension offers the column to elements inside a container only
        self::assertSame(
            ['colPos' => 200, 'inBackendLayout' => false],
            $this->placement(['type' => 'record', 'table' => 'tt_content', 'uid' => 74])['column'] ?? null,
        );
    }

    #[Test]
    public function backendLayoutAndItsOriginNeedPermissionForBothFields(): void
    {
        $this->loginBackendUser(self::LARS);
        self::assertSame(
            ['identifier' => 'pagets__two', 'title' => 'Two columns', 'source' => 'parentPage', 'sourcePage' => ['uid' => 30, 'title' => 'Section with two columns']],
            $this->placement(['type' => 'record', 'table' => 'tt_content', 'uid' => 70])['backendLayout'] ?? null,
        );
        self::assertSame(
            ['identifier' => 'pagets__single', 'title' => 'Single column', 'source' => 'page'],
            $this->placement(['type' => 'page', 'uid' => 32])['backendLayout'] ?? null,
        );
        self::assertSame(
            ['identifier' => '1', 'title' => 'Database layout', 'source' => 'page'],
            $this->placement(['type' => 'page', 'uid' => 39])['backendLayout'] ?? null,
        );
        self::assertSame(
            ['identifier' => 'none', 'title' => 'None', 'source' => 'page'],
            $this->placement(['type' => 'page', 'uid' => 33])['backendLayout'] ?? null,
        );
        // "About" [2] would inherit from "Home" [1], which is outside Lars' web mount
        self::assertArrayNotHasKey('backendLayout', $this->placement(['type' => 'page', 'uid' => 2]));
        $this->loginBackendUser(self::ADMIN);
        self::assertSame(['identifier' => 'default', 'source' => 'default'], $this->placement(['type' => 'page', 'uid' => 2])['backendLayout'] ?? null);

        foreach ([self::EDITOR, self::OLGA] as $userUid) {
            $this->loginBackendUser($userUid);
            $placement = $this->placement(['type' => 'record', 'table' => 'tt_content', 'uid' => 70]);
            self::assertArrayNotHasKey('backendLayout', $placement, 'User ' . $userUid);
            self::assertSame(5, $placement['column']['colPos'] ?? null, 'The column check needs no field permission');
        }
    }

    /**
     * The layout selection is the rule TYPO3 documents for the two fields;
     * the internal resolver of TYPO3 serves as reference here only.
     */
    #[Test]
    public function backendLayoutIdentifierMatchesTheSelectionOfTypo3(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['backend_layout_next_level' => '-1'], ['uid' => 1]);
        $this->loginBackendUser(self::ADMIN);
        $resolver = $this->get(PageLayoutResolver::class);

        foreach ([2, 30, 31, 32, 33, 39] as $uid) {
            $rootLine = BackendUtility::BEgetRootLine($uid, '', true);
            $page = reset($rootLine);
            array_pop($rootLine);
            self::assertSame(
                $resolver->getLayoutIdentifierForPage($page, $rootLine),
                $this->placement(['type' => 'page', 'uid' => $uid])['backendLayout']['identifier'] ?? null,
                'Page ' . $uid,
            );
        }
    }

    #[Test]
    public function backendLayoutFollowsTheWorkspaceOfTheReporter(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->update('be_users', ['usergroup' => '1,11'], ['uid' => self::EDITOR]);
        $this->loginInWorkspace(self::EDITOR, self::WORKSPACE_A);

        $placement = $this->placement(['type' => 'record', 'table' => 'tt_content', 'uid' => 70]);

        self::assertSame('pagets__single', $placement['backendLayout']['identifier'] ?? null);
        self::assertSame(['colPos' => 5, 'inBackendLayout' => false], $placement['column'] ?? null);
        self::assertSame([['colPos' => 0, 'label' => 'Content']], $placement['layoutColumns'] ?? null);
    }

    #[Test]
    public function inheritanceIsOnlyFollowedThroughAccessiblePages(): void
    {
        // A page below the editor's web mount [2] whose layout comes from "Home" [1]
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['backend_layout_next_level' => 'pagets__single'], ['uid' => 1]);
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->update('be_users', ['usergroup' => '1,11'], ['uid' => self::EDITOR]);
        $this->loginBackendUser(self::EDITOR);

        $placement = $this->placement(['type' => 'page', 'uid' => 2]);

        self::assertArrayNotHasKey('backendLayout', $placement, '"Home" is outside the web mount');
    }

    #[Test]
    public function contentFromAnotherPageIsNamedWhenAccessible(): void
    {
        $this->loginBackendUser(self::EDITOR);

        self::assertSame(['uid' => 2, 'title' => 'About'], $this->placement(['type' => 'page', 'uid' => 34])['contentFromPage'] ?? null);
        self::assertSame(['uid' => 4, 'notAccessible' => true], $this->placement(['type' => 'page', 'uid' => 35])['contentFromPage'] ?? null);
        self::assertSame(['uid' => 999, 'missing' => true], $this->placement(['type' => 'page', 'uid' => 36])['contentFromPage'] ?? null);

        $this->loginBackendUser(self::ADMIN);
        self::assertSame(['uid' => 4, 'title' => 'Other root'], $this->placement(['type' => 'page', 'uid' => 35])['contentFromPage'] ?? null);
    }

    #[Test]
    public function pagesShowingTheContentAreListedWhenAccessible(): void
    {
        $this->loginBackendUser(self::EDITOR);
        $expected = ['pages' => [['uid' => 34, 'title' => 'Mirror of About']], 'notAccessible' => 1];

        self::assertSame($expected, $this->placement(['type' => 'page', 'uid' => 2])['contentShownOn'] ?? null);
        self::assertSame($expected, $this->placement(['type' => 'record', 'table' => 'tt_content', 'uid' => 10])['contentShownOn'] ?? null);

        $this->loginBackendUser(self::ADMIN);
        self::assertSame(
            ['pages' => [['uid' => 34, 'title' => 'Mirror of About'], ['uid' => 37, 'title' => 'Mirror of About on the other root']]],
            $this->placement(['type' => 'page', 'uid' => 2])['contentShownOn'] ?? null,
        );
    }

    #[Test]
    public function otherSubjectsHaveNoPlacement(): void
    {
        $this->loginBackendUser(self::ADMIN);

        foreach ([
            ['source' => 'contextMenu', 'target' => ['type' => 'file', 'uid' => self::FILE_LOGO]],
            ['source' => 'contextMenu', 'target' => ['type' => 'record', 'table' => 'sys_file_metadata', 'uid' => 7]],
            ['source' => 'toolbar', 'location' => ['url' => '/typo3/module/site/configuration', 'module' => 'site_configuration']],
        ] as $input) {
            self::assertArrayNotHasKey('placement', $this->build($input)->toArray()['context'] ?? []);
        }
    }

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private function placement(array $target): array
    {
        return $this->build(['source' => 'contextMenu', 'target' => $target])->toArray()['context']['placement'] ?? [];
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
