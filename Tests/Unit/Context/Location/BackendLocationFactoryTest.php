<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Context\Location;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class BackendLocationFactoryTest extends UnitTestCase
{
    #[Test]
    public function pathAndPageIdAreExtractedWhileTokenAndHostAreDropped(): void
    {
        $location = (new BackendLocationFactory())->fromArray([
            'url' => 'https://evil.example.com/typo3/module/web/layout?token=abc123&id=42&returnUrl=%2Ftypo3%2Fmain&foo=bar',
            'module' => 'web_layout',
            'activeModule' => 'web_layout',
            'pageTreeSelection' => '42',
        ]);

        self::assertSame('/typo3/module/web/layout', $location->path);
        self::assertSame(42, $location->pageId);
        self::assertSame('web_layout', $location->moduleIdentifier);
        self::assertSame('web_layout', $location->activeModuleIdentifier);
        self::assertSame(42, $location->pageTreeSelection);
        self::assertSame(['id' => '42'], $location->getSafeQueryParameters());
    }

    #[Test]
    public function combinedFolderIdentifierOfTheFileListIsKeptSeparately(): void
    {
        $location = (new BackendLocationFactory())->fromArray([
            'url' => '/typo3/module/file/list?token=abc&id=1%3A%2Fuser_upload%2FGr%C3%B6%C3%9Fe%2F',
            'module' => 'media_management',
        ]);

        self::assertNull($location->pageId);
        self::assertSame('1:/user_upload/Größe/', $location->folderIdentifier);
        self::assertSame([], $location->getSafeQueryParameters());
    }

    #[Test]
    public function invalidFolderIdentifiersAreIgnored(): void
    {
        $factory = new BackendLocationFactory();

        self::assertSame('', $factory->fromArray(['url' => '/typo3/module/file/list?id=0%3A%2Ffileadmin%2F'])->folderIdentifier);
        self::assertSame('', $factory->fromArray(['url' => '/typo3/module/file/list?id=%2Fuser_upload%2F'])->folderIdentifier);
        self::assertSame('', $factory->fromArray(['url' => '/typo3/module/file/list?id=1%3A%2Fa%0Ab%2F'])->folderIdentifier);
        self::assertSame('', $factory->fromArray(['url' => '/typo3/module/file/list?id[]=1%3A%2F'])->folderIdentifier);
        self::assertSame('', $factory->fromArray(['url' => '/typo3/module/web/layout?id=12'])->folderIdentifier);
    }

    #[Test]
    public function formEngineEditConfigurationIsNormalized(): void
    {
        $location = (new BackendLocationFactory())->fromArray([
            'url' => '/typo3/record/edit?token=x&edit%5Btt_content%5D%5B12%2C13%5D=edit&edit%5Bpages%5D%5B5%5D=edit&columnsOnly%5Btt_content%5D%5B0%5D=header&columnsOnly%5Btt_content%5D%5B1%5D=bodytext',
        ]);

        self::assertSame('/typo3/record/edit', $location->path);
        self::assertSame([
            ['table' => 'tt_content', 'uid' => 12, 'command' => 'edit'],
            ['table' => 'tt_content', 'uid' => 13, 'command' => 'edit'],
            ['table' => 'pages', 'uid' => 5, 'command' => 'edit'],
        ], $location->editedRecords);
        self::assertSame(['tt_content' => ['header', 'bodytext']], $location->columnsOnly);
    }

    #[Test]
    public function newRecordConfigurationAndAllowlistedDefaultsAreKept(): void
    {
        $location = (new BackendLocationFactory())->fromArray([
            'url' => '/typo3/record/edit?edit[tt_content][7]=new&defVals[tt_content][colPos]=1&defVals[tt_content][CType]=textmedia&defVals[tt_content][bodytext]=' . rawurlencode(str_repeat('x', 300)),
        ]);

        self::assertSame([['table' => 'tt_content', 'uid' => 7, 'command' => 'new']], $location->editedRecords);
        self::assertSame(['tt_content' => ['colPos' => '1', 'CType' => 'textmedia']], $location->defaultValues);
    }

    #[Test]
    public function legacyColumnsOnlyStringIsAppliedToAllEditedTables(): void
    {
        $location = (new BackendLocationFactory())->fromArray([
            'url' => '/typo3/record/edit?edit[pages][3]=edit&columnsOnly=title,slug',
        ]);

        self::assertSame(['pages' => ['title', 'slug']], $location->columnsOnly);
    }

    #[Test]
    public function malformedEditConfigurationIsIgnored(): void
    {
        $factory = new BackendLocationFactory();

        $location = $factory->fromArray([
            'url' => '/typo3/record/edit?edit[tt_content;DROP][1]=edit&edit[pages][1 OR 1]=edit&edit[pages][2]=delete&edit[pages][abc]=edit&edit[pages][0]=edit',
        ]);
        self::assertSame([], $location->editedRecords);

        self::assertSame([], $factory->fromArray(['url' => '/typo3/record/edit?edit[pages]=5'])->editedRecords);
        self::assertSame([], $factory->fromArray(['url' => '/typo3/record/edit?edit=5'])->editedRecords);
    }

    #[Test]
    public function editedRecordsAreCapped(): void
    {
        $uids = implode(',', range(1, 60));
        $location = (new BackendLocationFactory())->fromArray(['url' => '/typo3/record/edit?edit[tt_content][' . $uids . ']=edit']);

        self::assertCount(BackendLocationFactory::MAX_EDITED_RECORDS, $location->editedRecords);
    }

    #[Test]
    public function invalidIdentifiersAndPathsAreDropped(): void
    {
        $location = (new BackendLocationFactory())->fromArray([
            'url' => 'javascript:alert(1)//<script>',
            'module' => 'web_layout"><img>',
            'activeModule' => ['array'],
            'pageTreeSelection' => '1:/fileadmin/',
        ]);

        self::assertSame('', $location->path);
        self::assertSame('', $location->moduleIdentifier);
        self::assertSame('', $location->activeModuleIdentifier);
        self::assertNull($location->pageTreeSelection);
        self::assertNull($location->pageId);
    }

    #[Test]
    public function listModuleTableParameterIsKeptWhenValid(): void
    {
        $factory = new BackendLocationFactory();

        self::assertSame('tx_news_domain_model_news', $factory->fromArray(['url' => '/typo3/module/web/list?id=1&table=tx_news_domain_model_news'])->listTable);
        self::assertSame('', $factory->fromArray(['url' => '/typo3/module/web/list?id=1&table=tt_content%27'])->listTable);
    }

    #[Test]
    public function missingInputCreatesAnEmptyLocation(): void
    {
        $location = (new BackendLocationFactory())->fromArray([]);

        self::assertTrue($location->isEmpty());
    }
}
