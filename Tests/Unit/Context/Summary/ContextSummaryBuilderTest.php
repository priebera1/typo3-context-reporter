<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Context\Summary;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\Summary\ContextSummaryBuilder;
use Priebera\ContextReporter\Tests\Unit\Fixtures\ReportFixture;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ContextSummaryBuilderTest extends UnitTestCase
{
    #[Test]
    public function recordSummaryNamesRecordPageSiteLanguageWorkspaceModuleAndForm(): void
    {
        $summary = (new ContextSummaryBuilder())->build([
            'subject' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 12, 'label' => 'Hero teaser', 'typeLabel' => 'Text & Media'],
            'context' => [
                'backend' => ['module' => ['identifier' => 'web_layout', 'title' => 'Page', 'group' => 'Web']],
                'page' => ['uid' => 1, 'title' => 'Home'],
                'record' => ['table' => 'tt_content', 'tableTitle' => 'Page Content', 'uid' => 12, 'label' => 'Hero teaser', 'type' => ['field' => 'CType', 'value' => 'textmedia', 'label' => 'Text & Media']],
                'formEngine' => ['mode' => 'edit', 'records' => [['table' => 'tt_content', 'uid' => 12]]],
                'site' => ['identifier' => 'main'],
                'language' => ['id' => 1, 'title' => 'Deutsch'],
                'workspace' => ['id' => 0, 'title' => 'Live'],
            ],
        ]);

        self::assertSame(
            'Page Content "Hero teaser" [tt_content:12] (Text & Media) on page "Home" [1] · site main · language Deutsch · workspace Live · module Web › Page · editing form',
            $summary,
        );
    }

    #[Test]
    public function newRecordSummaryMentionsTheTargetPage(): void
    {
        $summary = (new ContextSummaryBuilder())->build([
            'subject' => ['type' => 'record', 'table' => 'tx_news_domain_model_news', 'isNew' => true],
            'context' => [
                'page' => ['uid' => 7, 'title' => 'News storage'],
                'record' => ['table' => 'tx_news_domain_model_news', 'tableTitle' => 'News', 'isNew' => true, 'pid' => 7],
                'formEngine' => ['mode' => 'new'],
            ],
        ]);

        self::assertSame('New News record on page "News storage" [7] · new record form', $summary);
    }

    #[Test]
    public function pageSummaryIncludesSpecialPageTypes(): void
    {
        $builder = new ContextSummaryBuilder();

        self::assertSame(
            'Page "Storage" [9] (Folder) · site main',
            $builder->build([
                'subject' => ['type' => 'page', 'table' => 'pages', 'uid' => 9],
                'context' => ['page' => ['uid' => 9, 'title' => 'Storage', 'doktype' => 254, 'doktypeLabel' => 'Folder'], 'site' => ['identifier' => 'main']],
            ]),
        );
        self::assertSame(
            'Page "Home" [1]',
            $builder->build([
                'subject' => ['type' => 'page', 'table' => 'pages', 'uid' => 1],
                'context' => ['page' => ['uid' => 1, 'title' => 'Home', 'doktype' => 1, 'doktypeLabel' => 'Standard']],
            ]),
        );
    }

    #[Test]
    public function backendSummaryDescribesTheModuleOrRoute(): void
    {
        $builder = new ContextSummaryBuilder();

        self::assertSame(
            'Backend module "System › Scheduler" · workspace Live',
            $builder->build([
                'subject' => ['type' => 'backend'],
                'context' => [
                    'backend' => ['module' => ['identifier' => 'scheduler_manage', 'title' => 'Scheduler', 'group' => 'System']],
                    'workspace' => ['id' => 0, 'title' => 'Live'],
                ],
            ]),
        );
        self::assertSame(
            'Backend view "record_history"',
            $builder->build([
                'subject' => ['type' => 'backend'],
                'context' => ['backend' => ['route' => ['identifier' => 'record_history', 'path' => '/record/history']]],
            ]),
        );
        self::assertSame('TYPO3 backend', $builder->build(['subject' => ['type' => 'backend']]));
    }

    #[Test]
    public function fileSummaryNamesTheFileItsFolderAndStorage(): void
    {
        $document = ReportFixture::fileDocument();

        self::assertSame($document['summary'], (new ContextSummaryBuilder())->build($document));
    }

    #[Test]
    public function folderSummaryNamesTheCombinedIdentifierAndStorage(): void
    {
        $summary = (new ContextSummaryBuilder())->build([
            'subject' => ['type' => 'folder', 'label' => 'user_upload', 'identifier' => '1:/user_upload/'],
            'context' => [
                'folder' => ['storageUid' => 1, 'identifier' => '/user_upload/', 'name' => 'user_upload'],
                'storage' => ['uid' => 1, 'name' => 'fileadmin', 'driver' => 'Local', 'public' => true],
                'workspace' => ['id' => 0, 'title' => 'Live'],
            ],
        ]);

        self::assertSame('Folder "user_upload" [1:/user_upload/] · storage fileadmin · workspace Live', $summary);
    }

    #[Test]
    public function multipleRecordsInTheFormAreCounted(): void
    {
        $summary = (new ContextSummaryBuilder())->build([
            'subject' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 1],
            'context' => [
                'record' => ['table' => 'tt_content', 'tableTitle' => 'Page Content', 'uid' => 1, 'label' => 'A'],
                'formEngine' => ['mode' => 'edit', 'records' => [['table' => 'tt_content', 'uid' => 1], ['table' => 'tt_content', 'uid' => 2]]],
            ],
        ]);

        self::assertSame('Page Content "A" [tt_content:1] · editing form with 2 records', $summary);
    }
}
