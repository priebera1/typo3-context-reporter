<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\ReportTarget;
use Priebera\ContextReporter\Domain\SubjectType;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ReportTargetTest extends UnitTestCase
{
    #[Test]
    public function pagesAndRecordsAreParsed(): void
    {
        $page = ReportTarget::fromArray(['type' => 'page', 'uid' => '5', 'table' => 'tt_content']);
        self::assertSame(SubjectType::Page, $page->type);
        self::assertSame('pages', $page->table);
        self::assertSame(5, $page->uid);

        $record = ReportTarget::fromArray(['type' => 'record', 'table' => 'tt_content', 'uid' => 12]);
        self::assertSame(SubjectType::Record, $record->type);
        self::assertSame('tt_content', $record->table);
        self::assertSame(12, $record->uid);
        self::assertTrue($record->isExplicit());
    }

    #[Test]
    public function filesAreIdentifiedBySysFileUid(): void
    {
        $target = ReportTarget::fromArray(['type' => 'file', 'uid' => '12', 'table' => 'tt_content', 'identifier' => '1:/ignored.txt']);

        self::assertSame(SubjectType::File, $target->type);
        self::assertSame('sys_file', $target->table);
        self::assertSame(12, $target->uid);
        self::assertSame('', $target->identifier);
        self::assertTrue($target->isExplicit());
    }

    #[Test]
    public function foldersAreIdentifiedByCombinedIdentifier(): void
    {
        $target = ReportTarget::fromArray(['type' => 'folder', 'identifier' => '2:/Bilder/Größe 1/', 'uid' => 7]);

        self::assertSame(SubjectType::Folder, $target->type);
        self::assertSame('', $target->table);
        self::assertSame(0, $target->uid);
        self::assertSame('2:/Bilder/Größe 1/', $target->identifier);
        self::assertTrue($target->isExplicit());
    }

    #[Test]
    public function unknownTypesAreGenericBackendReports(): void
    {
        $target = ReportTarget::fromArray(['type' => 'storage', 'uid' => 1]);

        self::assertSame(SubjectType::Backend, $target->type);
        self::assertFalse($target->isExplicit());
        self::assertFalse(ReportTarget::fromArray([])->isExplicit());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidTargets(): array
    {
        return [
            'file without uid' => [['type' => 'file']],
            'file with zero uid' => [['type' => 'file', 'uid' => 0]],
            'file with text uid' => [['type' => 'file', 'uid' => '12abc']],
            'folder without identifier' => [['type' => 'folder']],
            'folder with numeric identifier' => [['type' => 'folder', 'identifier' => 1]],
            'folder without storage' => [['type' => 'folder', 'identifier' => '/user_upload/']],
            'folder in the fallback storage' => [['type' => 'folder', 'identifier' => '0:/fileadmin/']],
            'folder with relative path' => [['type' => 'folder', 'identifier' => '1:user_upload/']],
            'folder with control characters' => [['type' => 'folder', 'identifier' => "1:/user\nupload/"]],
            'folder with invalid UTF-8' => [['type' => 'folder', 'identifier' => "1:/\xC3\x28/"]],
            'folder with overlong identifier' => [['type' => 'folder', 'identifier' => '1:/' . str_repeat('a', 1100) . '/']],
            'record without table' => [['type' => 'record', 'uid' => 1]],
            'record with invalid table' => [['type' => 'record', 'table' => 'tt_content;drop', 'uid' => 1]],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('invalidTargets')]
    public function invalidTargetsAreRejected(array $data): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReportTarget::fromArray($data);
    }
}
