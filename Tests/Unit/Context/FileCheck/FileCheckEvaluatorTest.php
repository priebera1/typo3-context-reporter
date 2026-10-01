<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Context\FileCheck;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Priebera\ContextReporter\Context\FileCheck\FileCheckEvaluator;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceStorage;

final class FileCheckEvaluatorTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, int>, bool, list<string>}>
     */
    public static function indexedFileProvider(): iterable
    {
        yield 'fine' => [['size' => 99, 'missing' => 0], true, []];
        yield 'marked missing' => [['size' => 99, 'missing' => 1], true, [FileCheckEvaluator::MISSING]];
        yield 'offline storage' => [['size' => 99, 'missing' => 0], false, [FileCheckEvaluator::STORAGE_OFFLINE]];
        yield 'empty' => [['size' => 0, 'missing' => 0], true, [FileCheckEvaluator::EMPTY]];
        // The size of a missing file says nothing
        yield 'missing and empty in an offline storage' => [['size' => 0, 'missing' => 1], false, [FileCheckEvaluator::MISSING, FileCheckEvaluator::STORAGE_OFFLINE]];
    }

    /**
     * @param array<string, int> $properties
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('indexedFileProvider')]
    public function referencedFilesAreCheckedWithoutAskingTheStorage(array $properties, bool $online, array $expected): void
    {
        $storage = $this->createStorage($online);
        $storage->expects(self::never())->method('hasFile');
        $storage->expects(self::never())->method('getFileInfoByIdentifier');

        self::assertSame($expected, (new FileCheckEvaluator())->checkIndexedFile($this->createFile($storage, $properties)));
    }

    #[Test]
    public function aReportedFileIsLookedUpInItsStorage(): void
    {
        $evaluator = new FileCheckEvaluator();

        $storage = $this->createStorage(true);
        $storage->method('hasFile')->with('/user_upload/logo.png')->willReturn(true);
        self::assertSame(['storageCheck' => 'found', 'problems' => []], $evaluator->checkReportedFile($this->createFile($storage, ['size' => 99, 'missing' => 0])));

        $storage = $this->createStorage(true);
        $storage->method('hasFile')->willReturn(false);
        self::assertSame(
            ['storageCheck' => 'notFound', 'problems' => [FileCheckEvaluator::MISSING, FileCheckEvaluator::NOT_IN_STORAGE]],
            $evaluator->checkReportedFile($this->createFile($storage, ['size' => 0, 'missing' => 1])),
        );

        // The index may be outdated in both directions
        $storage = $this->createStorage(true);
        $storage->method('hasFile')->willReturn(false);
        self::assertSame(
            ['storageCheck' => 'notFound', 'problems' => [FileCheckEvaluator::NOT_IN_STORAGE]],
            $evaluator->checkReportedFile($this->createFile($storage, ['size' => 99, 'missing' => 0])),
        );
        $storage = $this->createStorage(true);
        $storage->method('hasFile')->willReturn(true);
        self::assertSame(
            ['storageCheck' => 'found', 'problems' => [FileCheckEvaluator::MISSING]],
            $evaluator->checkReportedFile($this->createFile($storage, ['size' => 99, 'missing' => 1])),
        );
    }

    #[Test]
    public function anOfflineStorageIsNotAsked(): void
    {
        $storage = $this->createStorage(false);
        $storage->expects(self::never())->method('hasFile');
        $storage->expects(self::never())->method('getFileInfoByIdentifier');

        self::assertSame(
            ['storageCheck' => 'notChecked', 'problems' => [FileCheckEvaluator::STORAGE_OFFLINE]],
            (new FileCheckEvaluator())->checkReportedFile($this->createFile($storage, ['size' => 99, 'missing' => 0])),
        );
    }

    #[Test]
    public function aFailedLookUpIsNotAFinding(): void
    {
        $storage = $this->createStorage(true);
        $storage->method('hasFile')->willThrowException(new InsufficientFolderAccessPermissionsException('You are not allowed to read folders', 1430657869));

        self::assertSame(
            ['storageCheck' => 'notChecked', 'problems' => []],
            (new FileCheckEvaluator())->checkReportedFile($this->createFile($storage, ['size' => 99, 'missing' => 0])),
        );
    }

    #[Test]
    public function theSizeOfAReportedFileComesFromTheStorageWhenTheIndexHasNone(): void
    {
        $evaluator = new FileCheckEvaluator();

        $storage = $this->createStorage(true);
        $storage->method('hasFile')->willReturn(true);
        $storage->method('getFileInfoByIdentifier')->willReturn(['size' => 2048]);
        self::assertSame(['storageCheck' => 'found', 'problems' => []], $evaluator->checkReportedFile($this->createFile($storage, ['size' => 0, 'missing' => 0])));

        $storage = $this->createStorage(true);
        $storage->method('hasFile')->willReturn(true);
        $storage->method('getFileInfoByIdentifier')->willReturn(['size' => 0]);
        self::assertSame(
            ['storageCheck' => 'found', 'problems' => [FileCheckEvaluator::EMPTY]],
            $evaluator->checkReportedFile($this->createFile($storage, ['size' => 0, 'missing' => 0])),
        );
    }

    private function createStorage(bool $online): ResourceStorage&MockObject
    {
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('isOnline')->willReturn($online);
        return $storage;
    }

    /**
     * @param array<string, int> $properties
     */
    private function createFile(ResourceStorage $storage, array $properties): File
    {
        return new File(['uid' => 1, 'identifier' => '/user_upload/logo.png', 'name' => 'logo.png'] + $properties, $storage);
    }
}
