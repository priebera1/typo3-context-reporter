<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Presentation;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\ContextDocumentBuilder;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Domain\ContextDocument;
use Priebera\ContextReporter\Presentation\SubjectPresentation;
use Priebera\ContextReporter\Presentation\SubjectPresenter;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;

final class SubjectPresenterTest extends AbstractContextReporterTestCase
{
    private const LONG_HEADER = 'Quarterly accessibility review of the product detail page teaser with extended campaign copy for autumn';

    #[Test]
    public function recordIsPresentedWithTypeIdentifierPageAndContext(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $presentation = $this->present([
            'source' => 'recordList',
            'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 10],
            'location' => ['url' => '/typo3/module/web/list?id=2', 'module' => 'web_list'],
        ]);

        self::assertSame('record', $presentation->kind);
        self::assertSame('Page Content · Regular Text Element', $presentation->typeLabel);
        self::assertSame('Hero teaser', $presentation->title);
        self::assertSame('tt_content:10', $presentation->identifier);
        self::assertSame('About', $presentation->location);
        self::assertSame('main · English · Live · ' . self::coreModuleLabel('web_list'), $presentation->getMetaLine());
        self::assertSame([
            ['key' => 'page', 'label' => 'Page', 'value' => 'About [2]'],
            ['key' => 'site', 'label' => 'Site', 'value' => 'main'],
            ['key' => 'language', 'label' => 'Language', 'value' => 'English'],
            ['key' => 'workspace', 'label' => 'Workspace', 'value' => 'Live'],
            ['key' => 'module', 'label' => 'Module', 'value' => self::coreModuleLabel('web_list')],
        ], $presentation->facts);
        self::assertSame('mimetypes-x-content-text', $presentation->iconIdentifier);
        self::assertSame('https://www.example.com/about', $presentation->frontendUrl);
        self::assertSame('https://backend.example.com/typo3/record/edit?edit%5Btt_content%5D%5B10%5D=edit', $presentation->backendUrl);
    }

    #[Test]
    public function recordWithoutTitleIsNamedAfterItsType(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $presentation = $this->present(['source' => 'recordList', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 13]]);

        self::assertSame('Page Content', $presentation->typeLabel);
        self::assertSame('Plain HTML', $presentation->title);
        self::assertSame('tt_content:13', $presentation->identifier);
    }

    #[Test]
    public function newRecordIsPresentedWithItsTableAndPage(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $presentation = $this->present([
            'source' => 'toolbar',
            'location' => ['url' => '/typo3/record/edit?edit%5Btt_content%5D%5B2%5D=new'],
        ]);

        self::assertSame('Page Content', $presentation->typeLabel);
        self::assertSame('New record', $presentation->title);
        self::assertSame('tt_content', $presentation->identifier);
        self::assertSame('About', $presentation->location);
    }

    #[Test]
    public function pageIsPresentedInTheBackendLanguageOfTheViewer(): void
    {
        $this->loginBackendUser(self::EDITOR);

        $presentation = $this->present([
            'source' => 'pageModule',
            'target' => ['type' => 'page', 'uid' => 2],
            'location' => ['url' => '/typo3/module/web/layout?id=2', 'module' => 'web_layout'],
        ]);

        self::assertSame('page', $presentation->kind);
        self::assertSame('Seite', $presentation->typeLabel);
        self::assertSame('About', $presentation->title);
        self::assertSame('UID 2', $presentation->identifier);
        self::assertSame('', $presentation->location);
        self::assertSame(['Site', 'Sprache', 'Arbeitsumgebung', 'Modul'], array_column($presentation->facts, 'label'));
        self::assertSame('main · English · Live · ' . self::coreModuleLabel('web_layout'), $presentation->getMetaLine());
        self::assertSame('apps-pagetree-page-default', $presentation->iconIdentifier);
        self::assertSame('https://www.example.com/about', $presentation->frontendUrl);
    }

    #[Test]
    public function specialPageTypesAreNamed(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $presentation = $this->present(['source' => 'contextMenu', 'target' => ['type' => 'page', 'uid' => 3]]);

        self::assertSame('Page · Folder', $presentation->typeLabel);
        self::assertSame('apps-pagetree-folder-default', $presentation->iconIdentifier);
        self::assertSame('', $presentation->frontendUrl);
    }

    #[Test]
    public function fileIsPresentedWithStorageAndFolder(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $presentation = $this->present([
            'source' => 'fileList',
            'target' => ['type' => 'file', 'uid' => self::FILE_LOGO],
            'location' => ['url' => '/typo3/module/file/list?id=1:/user_upload/', 'module' => 'media_management'],
        ]);

        self::assertSame('file', $presentation->kind);
        self::assertSame('File · image/png', $presentation->typeLabel);
        self::assertSame('logo.png', $presentation->title);
        self::assertSame('sys_file:1', $presentation->identifier);
        self::assertSame('fileadmin: /user_upload/', $presentation->location);
        self::assertSame([
            ['key' => 'storage', 'label' => 'Storage', 'value' => 'fileadmin'],
            ['key' => 'folder', 'label' => 'Folder', 'value' => '/user_upload/'],
            ['key' => 'workspace', 'label' => 'Workspace', 'value' => 'Live'],
            ['key' => 'module', 'label' => 'Module', 'value' => self::coreModuleLabel('media_management')],
        ], $presentation->facts);
        self::assertSame('Live · ' . self::coreModuleLabel('media_management'), $presentation->getMetaLine());
        self::assertSame('mimetypes-media-image', $presentation->iconIdentifier);
        self::assertSame('https://backend.example.com/typo3/module/file/list?id=1:/user_upload/', $presentation->backendUrl);
    }

    #[Test]
    public function folderIsPresentedWithItsCombinedIdentifier(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $presentation = $this->present(['source' => 'fileList', 'target' => ['type' => 'folder', 'identifier' => '1:/private/']]);

        self::assertSame('folder', $presentation->kind);
        self::assertSame('Folder', $presentation->typeLabel);
        self::assertSame('private', $presentation->title);
        self::assertSame('1:/private/', $presentation->identifier);
        self::assertSame('fileadmin', $presentation->location);
        self::assertSame('apps-filetree-folder-default', $presentation->iconIdentifier);
    }

    #[Test]
    public function backendModuleIsPresentedByItsTitle(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $presentation = $this->present([
            'source' => 'toolbar',
            'location' => ['url' => '/typo3/module/site/configuration', 'module' => 'site_configuration'],
        ]);

        self::assertSame('backend', $presentation->kind);
        self::assertSame('Backend module', $presentation->typeLabel);
        self::assertSame(self::coreModuleLabel('site_configuration'), $presentation->title);
        self::assertSame('site_configuration', $presentation->identifier);
        self::assertSame([['key' => 'workspace', 'label' => 'Workspace', 'value' => 'Live']], $presentation->facts);
        self::assertSame('Live', $presentation->getMetaLine());
        self::assertSame('module-sites', $presentation->iconIdentifier);
    }

    #[Test]
    public function genericBackendViewAndStoredLabelsOfRemovedTablesArePresented(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $presenter = $this->get(SubjectPresenter::class);

        $backend = $presenter->present(ContextDocument::fromArray(['subject' => ['type' => 'backend', 'label' => 'TYPO3 backend']]));
        self::assertSame('Backend view', $backend->typeLabel);
        self::assertSame('TYPO3 backend', $backend->title);
        self::assertSame('', $backend->identifier);
        self::assertSame([], $backend->facts);

        $removed = $presenter->present(ContextDocument::fromArray([
            'subject' => ['type' => 'record', 'table' => 'tx_removed_item', 'uid' => 4, 'label' => 'Old item'],
            'context' => ['record' => [
                'table' => 'tx_removed_item',
                'tableTitle' => 'Removed item',
                'uid' => 4,
                'label' => 'Old item',
                'type' => ['field' => 'kind', 'value' => 'x', 'label' => 'Stored type'],
            ]],
        ]));
        self::assertSame('Removed item · Stored type', $removed->typeLabel);
        self::assertSame('Old item', $removed->title);
        self::assertSame('tx_removed_item:4', $removed->identifier);
    }

    #[Test]
    public function longRecordTitlesAreReplacedByTheRecordTypeInTheReportList(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $long = $this->present(['source' => 'recordList', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 14]]);

        self::assertSame(self::LONG_HEADER, $long->title, 'The report detail shows the complete title');
        self::assertSame('Plain HTML', $long->listTitle);
        self::assertSame('Page Content · Plain HTML', $long->typeLabel);
        self::assertSame('tt_content:14', $long->identifier);
        self::assertSame('About', $long->location);

        $short = $this->present(['source' => 'recordList', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 10]]);
        self::assertSame('Hero teaser', $short->listTitle);
    }

    #[Test]
    public function longTitlesWithoutRecordTypeAreTruncatedInTheReportList(): void
    {
        $title = str_repeat('Very long page title ', 5);

        $presentation = $this->get(SubjectPresenter::class)->present(ContextDocument::fromArray([
            'subject' => ['type' => 'page', 'table' => 'pages', 'uid' => 2, 'label' => $title],
            'context' => ['page' => ['uid' => 2, 'title' => $title, 'doktype' => 1]],
        ]));

        self::assertSame($title, $presentation->title);
        self::assertSame(mb_substr($title, 0, 59) . '…', $presentation->listTitle);
    }

    #[Test]
    public function storedMultiLineLabelsAreNeverShown(): void
    {
        $label = "<div class=\"secret\">\nConfidential markup</div>";

        $presentation = $this->get(SubjectPresenter::class)->present(ContextDocument::fromArray([
            'subject' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 13, 'label' => $label],
            'context' => ['record' => [
                'table' => 'tt_content',
                'tableTitle' => 'Page Content',
                'uid' => 13,
                'label' => $label,
                'type' => ['field' => 'CType', 'value' => 'html', 'label' => 'Plain HTML'],
            ]],
        ]));

        self::assertSame('Plain HTML', $presentation->title);
        self::assertSame('Plain HTML', $presentation->listTitle);
        self::assertStringNotContainsString('Confidential', var_export($presentation, true));
    }

    #[Test]
    public function presentationIsSerializedForTheReportDialog(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $data = $this->present(['source' => 'contextMenu', 'target' => ['type' => 'page', 'uid' => 2]])->toArray();

        self::assertSame(['kind', 'icon', 'typeLabel', 'title', 'identifier', 'location', 'meta', 'facts'], array_keys($data));
        self::assertSame('main · English · Live', $data['meta']);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function present(array $input): SubjectPresentation
    {
        $request = CollectionRequest::fromArray($input, new BackendLocationFactory());
        $document = $this->get(ContextDocumentBuilder::class)->build($request, $GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST']);
        return $this->get(SubjectPresenter::class)->present($document);
    }
}
