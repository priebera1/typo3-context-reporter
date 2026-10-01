<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Context;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\ContextDocumentBuilder;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Domain\ContextDocument;
use Priebera\ContextReporter\Presentation\SubjectPresenter;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use Priebera\ContextReporter\Tests\Unit\Fixtures\ReportFixture;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * "context.visibility": the stored settings that decide whether TYPO3 shows
 * the reported page or record to website visitors, read within the
 * reporter's access. The fixture (visibility.csv) adds, below "About" [2]:
 * a members area with "Extend to subpages" [20] and its subpage [21], a
 * hidden section passing that on [22, 23], a scheduled page hidden in menus
 * [24, German translation hidden: 26] and an expired page [25]; outside the
 * editors' mount a hidden, restricted branch [70] with a page the editor Vera
 * [7] may access [71]. Vera may read "Extend to subpages" and use English and
 * German only; the editor Erika [2] may not read that field.
 */
final class VisibilityDiagnosticsTest extends AbstractContextReporterTestCase
{
    private const NOW = 1791790200;
    private const VERA = 7;
    private const WORKSPACE_A = 1;

    protected array $coreExtensionsToLoad = ['typo3/cms-filelist', 'typo3/cms-workspaces'];

    /** long_group_titles allows the frontend group title [3] that exceeds the reported length */
    protected array $testExtensionsToLoad = [
        'priebera/typo3-context-reporter',
        __DIR__ . '/../Fixtures/Extensions/long_group_titles',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/workspaces.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/visibility.csv');
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'https://www.example.com/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'navigationTitle' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'flag' => 'us'],
                ['languageId' => 1, 'title' => 'Deutsch', 'navigationTitle' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/', 'flag' => 'de'],
                ['languageId' => 2, 'title' => 'Français', 'navigationTitle' => 'Français', 'locale' => 'fr_FR.UTF-8', 'base' => '/fr/', 'flag' => 'fr', 'enabled' => false],
            ],
        ]);
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('@' . self::NOW)));
    }

    #[Test]
    public function hiddenScheduledExpiredAndRestrictedRecordsAreDescribed(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $hidden = $this->visibility(['type' => 'record', 'table' => 'tt_content', 'uid' => 51]);
        self::assertSame(self::time(self::NOW), $hidden['evaluatedAt']);
        self::assertSame(['reasons' => ['hidden'], 'hidden' => true], $hidden['subject']);

        self::assertSame(
            ['reasons' => ['scheduled'], 'starttime' => self::time(1792049400), 'endtime' => self::time(1798702200)],
            $this->visibility(['type' => 'record', 'table' => 'tt_content', 'uid' => 52])['subject'],
        );
        self::assertSame(
            ['reasons' => ['expired'], 'endtime' => self::time(1790926200)],
            $this->visibility(['type' => 'record', 'table' => 'tt_content', 'uid' => 53])['subject'],
        );
        self::assertSame(
            ['reasons' => ['accessRestricted'], 'frontendGroups' => [
                ['id' => 1, 'title' => 'Members'],
                ['id' => 2, 'title' => 'Partners'],
                ['id' => -1, 'title' => 'Hide at login'],
            ]],
            $this->visibility(['type' => 'record', 'table' => 'tt_content', 'uid' => 54])['subject'],
        );
        // A visible record on a visible page
        $visible = $this->visibility(['type' => 'record', 'table' => 'tt_content', 'uid' => 10]);
        self::assertSame(['reasons' => []], $visible['subject']);
        self::assertSame(['uid' => 2, 'title' => 'About', 'reasons' => []], $visible['page']);
    }

    #[Test]
    public function frontendGroupListsAreCapped(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $subject = $this->visibility(['type' => 'record', 'table' => 'tt_content', 'uid' => 55])['subject'];

        self::assertCount(10, $subject['frontendGroups']);
        self::assertSame(2, $subject['frontendGroupsNotListed']);
        self::assertSame(3, $subject['frontendGroups'][0]['id']);
        self::assertSame(101, mb_strlen($subject['frontendGroups'][0]['title']));
        self::assertStringEndsWith('…', $subject['frontendGroups'][0]['title']);
        self::assertSame(['id' => 12, 'title' => 'Group 12'], $subject['frontendGroups'][9]);
    }

    #[Test]
    public function pageSettingsIncludeSpecialGroupsAndMenuVisibility(): void
    {
        $this->loginBackendUser(self::ADMIN);

        self::assertSame(
            [
                'reasons' => ['scheduled', 'accessRestricted'],
                'starttime' => self::time(1792049400),
                'frontendGroups' => [['id' => -2, 'title' => 'Show at any login']],
                'hiddenInMenu' => true,
            ],
            $this->visibility(['type' => 'page', 'uid' => 24])['subject'],
        );
        self::assertSame(
            ['reasons' => ['expired', 'accessRestricted'], 'endtime' => self::time(1790926200), 'frontendGroups' => [['id' => -1, 'title' => 'Hide at login']]],
            $this->visibility(['type' => 'page', 'uid' => 25])['subject'],
        );
    }

    #[Test]
    public function parentPageRestrictionsAreReadOnlyWithFieldPermission(): void
    {
        $expectedMembersArea = ['uid' => 20, 'title' => 'Members area', 'reasons' => ['accessRestricted'], 'frontendGroups' => [['id' => 1, 'title' => 'Members']]];

        $this->loginBackendUser(self::ADMIN);
        $record = $this->visibility(['type' => 'record', 'table' => 'tt_content', 'uid' => 50]);
        self::assertSame(['uid' => 21, 'title' => 'Member news', 'reasons' => []], $record['page']);
        self::assertSame(['checkedUpToRoot' => true, 'restricting' => [$expectedMembersArea]], $record['parentPages']);
        self::assertSame(
            ['checkedUpToRoot' => true, 'restricting' => [['uid' => 22, 'title' => 'Hidden section', 'reasons' => ['hidden'], 'hidden' => true]]],
            $this->visibility(['type' => 'page', 'uid' => 23])['parentPages'],
        );

        $this->loginBackendUser(self::VERA);
        $page = $this->visibility(['type' => 'page', 'uid' => 21]);
        self::assertSame([$expectedMembersArea], $page['parentPages']['restricting']);
        self::assertFalse($page['parentPages']['checkedUpToRoot'], 'Home [1] is outside the mounts');

        // Without permission for "Extend to subpages" the inherited restriction is left out
        $this->loginBackendUser(self::EDITOR);
        $withoutPermission = $this->visibility(['type' => 'page', 'uid' => 21]);
        self::assertArrayNotHasKey('parentPages', $withoutPermission);
        self::assertSame(['reasons' => []], $withoutPermission['subject']);
    }

    #[Test]
    public function parentPagesOutsideTheReportersAccessAreNeverRead(): void
    {
        $this->loginBackendUser(self::ADMIN);
        self::assertSame(
            ['checkedUpToRoot' => true, 'restricting' => [[
                'uid' => 70,
                'title' => 'Private branch',
                'reasons' => ['hidden', 'accessRestricted'],
                'hidden' => true,
                'frontendGroups' => [['id' => 2, 'title' => 'Partners']],
            ]]],
            $this->visibility(['type' => 'page', 'uid' => 71])['parentPages'],
        );

        $this->loginBackendUser(self::VERA);
        $document = $this->build(['source' => 'contextMenu', 'target' => ['type' => 'page', 'uid' => 71]])->toArray();
        self::assertSame(['checkedUpToRoot' => false, 'restricting' => []], $document['context']['visibility']['parentPages']);
        $json = json_encode($document, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Private branch', $json);
        self::assertStringNotContainsString('Partners', $json);
    }

    #[Test]
    public function translationsAreListedForTheLanguagesTheReporterMayUse(): void
    {
        $this->loginBackendUser(self::ADMIN);
        self::assertSame([
            [
                'languageId' => 1,
                'title' => 'Deutsch',
                'page' => ['exists' => true, 'reasons' => []],
                'record' => ['exists' => true, 'reasons' => ['hidden'], 'hidden' => true],
            ],
            [
                'languageId' => 2,
                'title' => 'Français',
                'enabled' => false,
                'page' => ['exists' => false],
                'record' => ['exists' => false],
            ],
        ], $this->visibility(['type' => 'record', 'table' => 'tt_content', 'uid' => 10])['translations']);

        self::assertSame(
            ['languageId' => 1, 'title' => 'Deutsch', 'page' => ['exists' => true, 'reasons' => ['hidden'], 'hidden' => true]],
            $this->visibility(['type' => 'page', 'uid' => 24])['translations'][0],
        );

        // Vera may only use English and German
        $this->loginBackendUser(self::VERA);
        $translations = $this->visibility(['type' => 'record', 'table' => 'tt_content', 'uid' => 10])['translations'];
        self::assertSame([1], array_column($translations, 'languageId'));
        self::assertStringNotContainsString('Français', json_encode($translations, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function translationsAreDescribedFromTheTranslatedObject(): void
    {
        $this->loginBackendUser(self::ADMIN);

        // The German content element: its own settings and the German page only
        $translatedRecord = $this->visibility(['type' => 'record', 'table' => 'tt_content', 'uid' => 12]);
        self::assertSame(['reasons' => ['hidden'], 'hidden' => true], $translatedRecord['subject']);
        self::assertSame([['languageId' => 1, 'title' => 'Deutsch', 'page' => ['exists' => true, 'reasons' => []]]], $translatedRecord['translations']);

        // A page translation: its settings and those of the default language page
        $translatedPage = $this->visibility(['type' => 'page', 'uid' => 26]);
        self::assertSame(['reasons' => ['hidden'], 'hidden' => true], $translatedPage['subject']);
        self::assertSame('Coming soon', $translatedPage['page']['title']);
        self::assertSame(['scheduled', 'accessRestricted'], $translatedPage['page']['reasons']);
        self::assertArrayNotHasKey('translations', $translatedPage);
    }

    #[Test]
    public function workspaceStateDescribesWhatTheLiveWebsiteShows(): void
    {
        $this->loginInWorkspace(self::EDITOR, self::WORKSPACE_A);

        self::assertSame('changed', $this->visibility(['type' => 'record', 'table' => 'tt_content', 'uid' => 10])['subject']['workspaceState']);
        self::assertSame('new', $this->visibility(['type' => 'record', 'table' => 'tt_content', 'uid' => 32])['subject']['workspaceState']);
        self::assertSame('deleted', $this->visibility(['type' => 'record', 'table' => 'tt_content', 'uid' => 56])['subject']['workspaceState']);
        self::assertSame('unchanged', $this->visibility(['type' => 'record', 'table' => 'tt_content', 'uid' => 51])['subject']['workspaceState']);
        self::assertSame('new', $this->visibility(['type' => 'page', 'uid' => 42])['subject']['workspaceState']);

        $this->loginInWorkspace(self::EDITOR, 0);
        self::assertArrayNotHasKey('workspaceState', $this->visibility(['type' => 'record', 'table' => 'tt_content', 'uid' => 10])['subject']);
    }

    #[Test]
    public function otherSubjectsHaveNoVisibilitySection(): void
    {
        $this->loginBackendUser(self::ADMIN);

        foreach ([
            ['source' => 'fileList', 'target' => ['type' => 'file', 'uid' => self::FILE_LOGO]],
            ['source' => 'toolbar', 'location' => ['url' => '/typo3/module/site/configuration', 'module' => 'site_configuration']],
            ['source' => 'formEngine', 'location' => ['url' => '/typo3/record/edit?edit[tt_content][2]=new']],
        ] as $input) {
            self::assertArrayNotHasKey('visibility', $this->build($input)->toArray()['context'] ?? []);
        }
    }

    #[Test]
    public function noticesDescribeTheSettingsInTheViewersLanguage(): void
    {
        $this->loginBackendUser(self::ADMIN);
        self::assertSame(
            [
                'title' => 'Visibility settings',
                'notices' => [
                    'Frontend access: Members, Partners, Hide at login',
                    'Page not translated into: Français',
                    'Not translated into: Deutsch, Français',
                    'Disabled in the site configuration: Français',
                ],
                'note' => 'These are the settings stored in TYPO3. Templates, caches, extensions and other frontend logic can still change what the website shows.',
            ],
            $this->present(['type' => 'record', 'table' => 'tt_content', 'uid' => 54])->visibility,
        );
        $launch = $this->build(['source' => 'contextMenu', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 58]]);
        self::assertSame(
            'Page "Coming soon": Publishing starts on ' . self::displayTime($launch, 'page') . ' · Frontend access: Show at any login · Hidden in menus',
            $this->presentDocument($launch)->visibility['notices'][0] ?? '',
        );
        self::assertSame(
            'Parent page "Members area", applies to its subpages: Frontend access: Members',
            $this->present(['type' => 'page', 'uid' => 21])->visibility['notices'][0] ?? '',
        );

        // Erika's backend is German
        $this->loginBackendUser(self::EDITOR);
        $scheduled = $this->build(['source' => 'contextMenu', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 52]]);
        self::assertSame(
            [
                'Veröffentlichung ab ' . self::displayTime($scheduled, 'subject'),
                'Seite nicht übersetzt in: Français',
                'Nicht übersetzt in: Deutsch, Français',
                'In der Site-Konfiguration deaktiviert: Français',
            ],
            $this->presentDocument($scheduled)->visibility['notices'] ?? [],
        );

        // Workspace A has a draft of the page "About" [2] and of the element [10]
        $this->loginInWorkspace(self::EDITOR, self::WORKSPACE_A);
        $changed = $this->present(['type' => 'record', 'table' => 'tt_content', 'uid' => 10])->visibility;
        self::assertSame('Sichtbarkeitseinstellungen', $changed['title'] ?? '');
        self::assertSame(
            [
                'In dieser Arbeitsumgebung geändert, die Live-Website zeigt die veröffentlichte Version',
                'Seite „About draft A“: In dieser Arbeitsumgebung geändert, die Live-Website zeigt die veröffentlichte Version',
                'Übersetzung Deutsch: Verborgen',
                'Seite nicht übersetzt in: Français',
                'Nicht übersetzt in: Français',
                'In der Site-Konfiguration deaktiviert: Français',
            ],
            $changed['notices'] ?? [],
        );
        self::assertSame(
            'Neu in dieser Arbeitsumgebung, noch nicht auf der Live-Website',
            $this->present(['type' => 'record', 'table' => 'tt_content', 'uid' => 32])->visibility['notices'][0] ?? '',
        );
        self::assertSame(
            'In dieser Arbeitsumgebung gelöscht, bis zur Veröffentlichung noch auf der Live-Website',
            $this->present(['type' => 'record', 'table' => 'tt_content', 'uid' => 56])->visibility['notices'][0] ?? '',
        );
    }

    #[Test]
    public function reportsWithoutVisibilityDataHaveNoNotices(): void
    {
        $this->loginBackendUser(self::ADMIN);

        // A report stored before the visibility settings were collected
        self::assertNull($this->presentDocument(ContextDocument::fromArray(ReportFixture::document()))->visibility);
        // Visible page with visible translations only
        $this->loginBackendUser(self::VERA);
        self::assertNull($this->present(['type' => 'page', 'uid' => 2])->visibility);
    }

    #[Test]
    public function deepPageTreesAndManyLanguagesStayWithinLimits(): void
    {
        $languages = [['languageId' => 0, 'title' => 'English', 'navigationTitle' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'flag' => 'us']];
        for ($languageId = 1; $languageId <= 40; $languageId++) {
            $languages[] = ['languageId' => $languageId, 'title' => 'Language ' . $languageId, 'navigationTitle' => 'L' . $languageId, 'locale' => 'en_US.UTF-8', 'base' => '/l' . $languageId . '/', 'flag' => 'us'];
        }
        $this->get(SiteWriter::class)->write('main', ['rootPageId' => 1, 'base' => 'https://www.example.com/', 'languages' => $languages]);
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('pages');
        $parent = 2;
        for ($uid = 200; $uid < 225; $uid++) {
            $connection->insert('pages', ['uid' => $uid, 'pid' => $parent, 'title' => 'Level ' . $uid, 'doktype' => 1, 'perms_userid' => 1, 'perms_groupid' => 1, 'perms_user' => 31, 'perms_group' => 31, 'extendToSubpages' => 1, 'hidden' => $uid === 200 ? 1 : 0]);
            $parent = $uid;
        }
        $this->loginBackendUser(self::ADMIN);

        $started = hrtime(true);
        $visibility = $this->visibility(['type' => 'page', 'uid' => 224]);
        $milliseconds = (hrtime(true) - $started) / 1e6;

        self::assertFalse($visibility['parentPages']['checkedUpToRoot'], 'Parent pages are read up to 20 levels');
        self::assertSame([], $visibility['parentPages']['restricting'], 'The hidden page [200] is more than 20 levels up');
        self::assertCount(30, $visibility['translations'], 'At most 30 languages are listed');
        self::assertLessThan(5000, $milliseconds);
    }

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private function visibility(array $target): array
    {
        $document = $this->build(['source' => 'contextMenu', 'target' => $target])->toArray();
        self::assertIsArray($document['context']['visibility'] ?? null);
        return $document['context']['visibility'];
    }

    /**
     * @param array<string, mixed> $target
     */
    private function present(array $target): \Priebera\ContextReporter\Presentation\SubjectPresentation
    {
        return $this->presentDocument($this->build(['source' => 'contextMenu', 'target' => $target]));
    }

    private function presentDocument(ContextDocument $document): \Priebera\ContextReporter\Presentation\SubjectPresentation
    {
        return $this->get(SubjectPresenter::class)->present($document);
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

    /**
     * The start time of the subject or its page as the notices show it (TYPO3 backend date format)
     */
    private static function displayTime(ContextDocument $document, string $object): string
    {
        $time = $document->toArray()['context']['visibility'][$object]['starttime'] ?? '';
        $format = $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] . ' ' . $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'];
        return (new \DateTimeImmutable(is_string($time) ? $time : ''))->format($format);
    }

    private static function time(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format(\DateTimeInterface::ATOM);
    }
}
