<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Context;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\ContextDocumentBuilder;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Context\Subject\SubjectNotAvailableException;
use Priebera\ContextReporter\Domain\ContextDocument;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Information\Typo3Version;

final class ContextDocumentBuilderTest extends AbstractContextReporterTestCase
{
    #[Test]
    public function recordReportContainsAllowlistedMetadataButNoFieldContent(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $document = $this->build(['source' => 'contextMenu', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 10]]);
        $data = $document->toArray();

        self::assertSame([
            'type' => 'record',
            'table' => 'tt_content',
            'uid' => 10,
            'label' => 'Hero teaser',
            'typeLabel' => 'Regular Text Element',
            'backendUrl' => 'https://backend.example.com/typo3/record/edit?edit%5Btt_content%5D%5B10%5D=edit',
        ], $data['subject']);

        $record = $data['context']['record'];
        self::assertSame('tt_content', $record['table']);
        self::assertSame('Page Content', $record['tableTitle']);
        self::assertSame(2, $record['pid']);
        self::assertSame(['field' => 'CType', 'value' => 'text', 'label' => 'Regular Text Element'], $record['type']);
        self::assertSame(0, $record['languageId']);
        self::assertSame(0, $record['colPos']);
        self::assertFalse($record['hidden']);

        $page = $data['context']['page'];
        self::assertSame(2, $page['uid']);
        self::assertSame('About', $page['title']);
        self::assertSame('/about', $page['slug']);
        self::assertSame([['uid' => 1, 'title' => 'Home'], ['uid' => 2, 'title' => 'About']], $page['rootline']);
        self::assertSame('https://www.example.com/about', $page['frontendUrl']);
        self::assertSame('https://backend.example.com/typo3/module/web/layout?id=2', $page['backendUrl']);

        self::assertSame(['identifier' => 'main', 'base' => 'https://www.example.com/', 'rootPageId' => 1], $data['context']['site']);
        self::assertSame(['id' => 0, 'title' => 'English', 'locale' => 'en-US'], $data['context']['language']);
        self::assertSame(['id' => 0, 'title' => 'Live'], $data['context']['workspace']);
        self::assertSame('Functional Test Portal', $data['project']['name']);
        self::assertSame('https://backend.example.com/typo3/', $data['project']['backendUrl']);
        self::assertSame(['uid' => 1, 'username' => 'admin'], $data['reporter']);
        self::assertMatchesRegularExpression('/^' . preg_quote((new Typo3Version())->getBranch(), '/') . '\.\d+$/', $data['system']['typo3Version']);
        self::assertSame($this->getDatabasePlatformName(), explode(' ', $data['system']['databasePlatform'])[0]);
        self::assertSame(
            'Page Content "Hero teaser" [tt_content:10] (Regular Text Element) on page "About" [2] · site main · language English · workspace Live',
            $data['summary'],
        );

        $json = json_encode($data, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Confidential body text', $json);
        self::assertStringNotContainsString('token', $json);
    }

    #[Test]
    public function multilineTextIsNeverUsedAsRecordLabel(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $data = $this->build(['source' => 'recordList', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 13]])->toArray();

        self::assertArrayNotHasKey('label', $data['subject']);
        self::assertArrayNotHasKey('label', $data['context']['record']);
        self::assertSame('Plain HTML', $data['context']['record']['type']['label']);
        self::assertSame(
            'Page Content [tt_content:13] (Plain HTML) on page "About" [2] · site main · language English · workspace Live',
            $data['summary'],
        );
        self::assertStringNotContainsString('Confidential', json_encode($data, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function translatedHiddenRecordReportsItsLanguageAndTranslationSource(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $data = $this->build(['source' => 'contextMenu', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 12]])->toArray();

        self::assertSame(1, $data['context']['record']['languageId']);
        self::assertSame(10, $data['context']['record']['translationSourceUid']);
        self::assertTrue($data['context']['record']['hidden']);
        self::assertSame(['id' => 1, 'title' => 'Deutsch', 'locale' => 'de-DE'], $data['context']['language']);
        self::assertSame('https://www.example.com/de/about', $data['context']['page']['frontendUrl']);
    }

    #[Test]
    public function folderPageHasATypeButNoFrontendUrl(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $data = $this->build(['source' => 'contextMenu', 'target' => ['type' => 'page', 'uid' => 3]])->toArray();

        self::assertSame('page', $data['subject']['type']);
        self::assertSame('Page (Folder)', $data['subject']['typeLabel']);
        self::assertSame(254, $data['context']['page']['doktype']);
        self::assertSame('Folder', $data['context']['page']['doktypeLabel']);
        self::assertArrayNotHasKey('frontendUrl', $data['context']['page']);
        self::assertArrayNotHasKey('record', $data['context']);
        self::assertSame('Page "Storage" [3] (Folder) · site main · language English · workspace Live', $data['summary']);
    }

    #[Test]
    public function editorCannotReportObjectsOutsideTheirPermissions(): void
    {
        $this->loginBackendUser(self::EDITOR);

        foreach ([
            ['type' => 'record', 'table' => 'tt_content', 'uid' => 11],
            ['type' => 'page', 'uid' => 4],
            ['type' => 'page', 'uid' => 1],
            ['type' => 'page', 'uid' => 5],
            ['type' => 'record', 'table' => 'be_users', 'uid' => 1],
            ['type' => 'record', 'table' => 'sys_file', 'uid' => 1],
            ['type' => 'record', 'table' => 'tt_content', 'uid' => 999],
        ] as $target) {
            try {
                $this->build(['source' => 'contextMenu', 'target' => $target]);
                self::fail('Expected the subject to be unavailable: ' . json_encode($target));
            } catch (SubjectNotAvailableException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function rootlineStopsAtTheEditorsWebMount(): void
    {
        $this->loginBackendUser(self::EDITOR);

        $data = $this->build(['source' => 'contextMenu', 'target' => ['type' => 'page', 'uid' => 3]])->toArray();

        self::assertSame([['uid' => 2, 'title' => 'About'], ['uid' => 3, 'title' => 'Storage']], $data['context']['page']['rootline']);
    }

    #[Test]
    public function toolbarReportInTheRecordEditorDescribesTheOpenRecordAndForm(): void
    {
        $this->loginBackendUser(self::EDITOR);

        $data = $this->build([
            'source' => 'toolbar',
            'location' => [
                'url' => '/typo3/record/edit?token=secret-token&edit%5Btt_content%5D%5B10%5D=edit&columnsOnly%5Btt_content%5D%5B0%5D=header&columnsOnly%5Btt_content%5D%5B1%5D=not_a_field&returnUrl=%2Ftypo3%2Fmodule%2Fweb%2Flayout%3Fid%3D2',
                'module' => 'record_edit',
                'activeModule' => 'web_layout',
                'pageTreeSelection' => '2',
            ],
        ])->toArray();

        self::assertSame('record', $data['subject']['type']);
        self::assertSame(10, $data['subject']['uid']);
        self::assertSame([
            'mode' => 'edit',
            'records' => [['table' => 'tt_content', 'uid' => 10]],
            'columnsOnly' => ['tt_content' => ['header']],
        ], $data['context']['formEngine']);
        self::assertSame(['identifier' => 'record_edit', 'path' => '/record/edit'], $data['context']['backend']['route']);
        self::assertSame(['identifier' => 'web_layout'] + self::coreModule('web_layout'), $data['context']['backend']['module']);
        self::assertSame('de', $data['context']['backend']['backendLanguage']);
        self::assertStringEndsWith('· module ' . self::coreModuleLabel('web_layout') . ' · editing form', $data['summary']);
        self::assertStringNotContainsString('secret-token', json_encode($data, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function newRecordFormReportsOnlyStructuralDefaults(): void
    {
        $this->loginBackendUser(self::EDITOR);

        $data = $this->build([
            'source' => 'formEngine',
            'location' => [
                'url' => '/typo3/record/edit?edit[tt_content][2]=new&defVals[tt_content][CType]=textmedia&defVals[tt_content][colPos]=1&defVals[tt_content][header]=Injected',
            ],
        ])->toArray();

        self::assertSame(['type' => 'record', 'table' => 'tt_content', 'isNew' => true, 'label' => 'New record', 'typeLabel' => 'Page Content', 'pid' => 2], $data['subject']);
        self::assertSame(['table' => 'tt_content', 'tableTitle' => 'Page Content', 'isNew' => true, 'pid' => 2], $data['context']['record']);
        self::assertSame([
            'mode' => 'new',
            'newRecord' => ['table' => 'tt_content', 'pid' => 2, 'defaults' => ['CType' => 'textmedia', 'colPos' => '1']],
        ], $data['context']['formEngine']);
        self::assertStringStartsWith('New Page Content record on page "About" [2]', $data['summary']);
    }

    #[Test]
    public function pageModuleReportUsesThePageAndTheSelectedLanguage(): void
    {
        $backendUser = $this->loginBackendUser(self::EDITOR);
        $backendUser->pushModuleData('web_layout', ['language' => 1]);

        $data = $this->build([
            'source' => 'toolbar',
            'location' => ['url' => '/typo3/module/web/layout?id=2', 'module' => 'web_layout'],
        ])->toArray();

        self::assertSame('page', $data['subject']['type']);
        self::assertSame(2, $data['subject']['uid']);
        self::assertSame(['identifier' => 'web_layout'] + self::coreModule('web_layout'), $data['context']['backend']['module']);
        self::assertSame(['id' => '2'], $data['context']['backend']['parameters']);
        self::assertSame('Deutsch', $data['context']['language']['title']);
    }

    #[Test]
    public function pageTreeSelectionIsIgnoredWhenItIsNotAccessible(): void
    {
        $this->loginBackendUser(self::EDITOR);

        $data = $this->build([
            'source' => 'toolbar',
            'location' => ['url' => '/typo3/module/web/layout', 'module' => 'web_layout', 'pageTreeSelection' => '4'],
        ])->toArray();

        self::assertSame('backend', $data['subject']['type']);
        self::assertArrayNotHasKey('page', $data['context']);
        self::assertSame('Backend module "' . self::coreModuleLabel('web_layout') . '" · workspace Live', $data['summary']);
    }

    #[Test]
    public function moduleWithoutPageTreeIsReportedAsBackendView(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $data = $this->build([
            'source' => 'toolbar',
            'location' => ['url' => '/typo3/module/site/configuration', 'module' => 'site_configuration', 'pageTreeSelection' => '2'],
        ])->toArray();

        self::assertSame('backend', $data['subject']['type']);
        self::assertSame('site_configuration', $data['subject']['module']);
        self::assertSame('site_configuration', $data['context']['backend']['module']['identifier']);
        self::assertArrayNotHasKey('page', $data['context']);
    }

    #[Test]
    public function reporterDetailsFollowThePrivacySettings(): void
    {
        $this->configureExtension(['privacy' => [
            'reporterUid' => '1',
            'reporterUsername' => '1',
            'reporterRealName' => '1',
            'reporterEmail' => '1',
            'reporterGroups' => '1',
        ]]);
        $this->loginBackendUser(self::EDITOR);

        $data = $this->build(['source' => 'toolbar'])->toArray();

        self::assertSame([
            'uid' => 2,
            'username' => 'editor',
            'realName' => 'Erika Editor',
            'email' => 'erika@example.com',
            'admin' => false,
            'groups' => [['uid' => 1, 'title' => 'Editors']],
        ], $data['reporter']);
    }

    #[Test]
    public function reporterAndBrowserCanBeWithheldCompletely(): void
    {
        $this->configureExtension(['privacy' => [
            'reporterUid' => '0',
            'reporterUsername' => '0',
            'browserDetails' => '0',
        ]]);
        $this->loginBackendUser(self::EDITOR);

        $data = $this->build(['source' => 'toolbar', 'browser' => ['userAgent' => 'Mozilla/5.0', 'language' => 'de']])->toArray();

        self::assertArrayNotHasKey('reporter', $data);
        self::assertArrayNotHasKey('browser', $data);
    }

    #[Test]
    public function browserDetailsAreSanitized(): void
    {
        $this->loginBackendUser(self::EDITOR);

        $data = $this->build(['source' => 'toolbar', 'browser' => [
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
            'cookie' => 'be_typo_user=secret',
            'viewport' => ['width' => 1280, 'height' => 720],
        ]])->toArray();

        self::assertSame('Chrome 129 · Windows · 1280×720', $data['browser']['summary']);
        self::assertArrayNotHasKey('cookie', $data['browser']);
    }

    #[Test]
    public function recentBackendErrorsAreAnOptInAndLimitedToTheReporter(): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('sys_log');
        $rows = [
            ['tstamp' => time() - 60, 'userid' => 2, 'error' => 1, 'details' => 'Attempt to modify record \'%s\' (%s) without permission. Or non-existing page.', 'log_data' => '["Hero teaser","tt_content:10"]', 'tablename' => 'tt_content', 'recuid' => 10],
            ['tstamp' => time() - 60, 'userid' => 2, 'error' => 0, 'details' => 'Record was updated.', 'log_data' => '[]', 'tablename' => 'tt_content', 'recuid' => 10],
            ['tstamp' => time() - 60, 'userid' => 1, 'error' => 1, 'details' => 'Error of another user', 'log_data' => '[]', 'tablename' => '', 'recuid' => 0],
            ['tstamp' => time() - 7200, 'userid' => 2, 'error' => 1, 'details' => 'Old error', 'log_data' => '[]', 'tablename' => '', 'recuid' => 0],
        ];
        foreach ($rows as $row) {
            $connection->insert('sys_log', $row + ['type' => 1, 'action' => 2, 'event_pid' => 2]);
        }
        $this->loginBackendUser(self::EDITOR);

        self::assertArrayNotHasKey('recentErrors', $this->build(['source' => 'toolbar'])->toArray()['context'] ?? []);

        $this->configureExtension(['privacy' => ['recentBackendErrors' => '1']]);
        $section = $this->build(['source' => 'toolbar'])->toArray()['context']['recentErrors'];

        self::assertSame(30, $section['windowMinutes']);
        self::assertCount(1, $section['entries']);
        self::assertSame('error', $section['entries'][0]['severity']);
        self::assertSame("Attempt to modify record 'Hero teaser' (tt_content:10) without permission. Or non-existing page.", $section['entries'][0]['message']);
        self::assertSame('tt_content', $section['entries'][0]['table']);
        self::assertSame(10, $section['entries'][0]['recordUid']);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function build(array $input): ContextDocument
    {
        $request = CollectionRequest::fromArray($input, new BackendLocationFactory());
        return $this->get(ContextDocumentBuilder::class)->build($request, $GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST']);
    }

    /**
     * The functional tests run on SQLite by default and on MariaDB/MySQL in CI
     */
    private function getDatabasePlatformName(): string
    {
        $platform = $this->get(ConnectionPool::class)
            ->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)
            ->getDatabasePlatform();
        return match (true) {
            $platform instanceof MariaDBPlatform => 'MariaDB',
            $platform instanceof AbstractMySQLPlatform => 'MySQL',
            $platform instanceof PostgreSQLPlatform => 'PostgreSQL',
            $platform instanceof SQLitePlatform => 'SQLite',
            default => $platform::class,
        };
    }
}
