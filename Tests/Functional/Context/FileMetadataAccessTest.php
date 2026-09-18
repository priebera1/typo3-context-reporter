<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\ContextDocumentBuilder;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Context\Subject\SubjectNotAvailableException;
use Priebera\ContextReporter\Domain\ContextDocument;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;

/**
 * File metadata and file references are records, but they identify files:
 * they may only be reported when the reporter can access the file itself.
 * sys_file 1 (logo.png) is inside the editor's file mount, sys_file 2
 * (budget.txt) is not.
 */
final class FileMetadataAccessTest extends AbstractContextReporterTestCase
{
    private const METADATA_EDITOR = 5;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/file_metadata.csv');
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function accessibleRecordProvider(): iterable
    {
        yield 'metadata of a file in the mount' => ['sys_file_metadata', 7];
        yield 'translated metadata of a file in the mount' => ['sys_file_metadata', 9];
        yield 'translated metadata without file of a file in the mount' => ['sys_file_metadata', 11];
        yield 'file reference on an accessible page' => ['sys_file_reference', 20];
        yield 'root level file reference of a file in the mount' => ['sys_file_reference', 22];
    }

    #[Test]
    #[DataProvider('accessibleRecordProvider')]
    public function metadataAndReferencesOfAccessibleFilesCanBeReported(string $table, int $uid): void
    {
        $this->loginBackendUser(self::METADATA_EDITOR);

        $data = $this->build(['source' => 'contextMenu', 'target' => ['type' => 'record', 'table' => $table, 'uid' => $uid]])->toArray();

        self::assertSame('record', $data['subject']['type']);
        self::assertSame($table, $data['subject']['table']);
        self::assertSame($uid, $data['subject']['uid']);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function inaccessibleRecordProvider(): iterable
    {
        yield 'metadata of a file outside the mount' => ['sys_file_metadata', 8];
        yield 'translated metadata of a file outside the mount' => ['sys_file_metadata', 10];
        yield 'translated metadata without file of a file outside the mount' => ['sys_file_metadata', 12];
        yield 'metadata of an unknown file' => ['sys_file_metadata', 13];
        yield 'translation of metadata of a file outside the mount' => ['sys_file_metadata', 14];
        yield 'unknown metadata' => ['sys_file_metadata', 4711];
        yield 'root level file reference of a file outside the mount' => ['sys_file_reference', 21];
    }

    #[Test]
    #[DataProvider('inaccessibleRecordProvider')]
    public function metadataAndReferencesOfInaccessibleFilesCannotBeReported(string $table, int $uid): void
    {
        $this->loginBackendUser(self::METADATA_EDITOR);

        $this->expectException(SubjectNotAvailableException::class);
        $this->build(['source' => 'contextMenu', 'target' => ['type' => 'record', 'table' => $table, 'uid' => $uid]]);
    }

    #[Test]
    public function administratorsCanReportMetadataOfAllFiles(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $data = $this->build(['source' => 'contextMenu', 'target' => ['type' => 'record', 'table' => 'sys_file_metadata', 'uid' => 8]])->toArray();

        self::assertSame(8, $data['subject']['uid']);
        self::assertSame('budget.txt', $data['subject']['label']);
    }

    #[Test]
    public function metadataFormOfAnAccessibleFileIsTheSubject(): void
    {
        $this->loginBackendUser(self::METADATA_EDITOR);

        $data = $this->build([
            'source' => 'formEngine',
            'location' => ['url' => '/typo3/record/edit?edit%5Bsys_file_metadata%5D%5B7%5D=edit'],
        ])->toArray();

        self::assertSame('sys_file_metadata', $data['subject']['table']);
        self::assertSame(7, $data['subject']['uid']);
        self::assertSame('logo.png', $data['subject']['label']);
        self::assertSame([['table' => 'sys_file_metadata', 'uid' => 7]], $data['context']['formEngine']['records']);
    }

    #[Test]
    public function metadataFormOfAnInaccessibleFileRevealsNothingAboutTheFile(): void
    {
        $this->loginBackendUser(self::METADATA_EDITOR);

        foreach ([
            '/typo3/record/edit?edit%5Bsys_file_metadata%5D%5B8%5D=edit',
            '/typo3/record/edit?edit%5Bsys_file_metadata%5D%5B10%5D=edit',
            '/typo3/record/edit?edit%5Bsys_file_metadata%5D%5B8%2C7%5D=edit',
            '/typo3/record/edit?edit%5Bsys_file_metadata%5D%5B0%5D=new',
        ] as $url) {
            $data = $this->build(['source' => 'formEngine', 'location' => ['url' => $url, 'pageTreeSelection' => '2']])->toArray();
            $json = json_encode($data, JSON_THROW_ON_ERROR);

            self::assertNotSame('sys_file_metadata', $data['subject']['table'] ?? '', $url);
            self::assertStringNotContainsString('budget', $json, $url);
            self::assertStringNotContainsString('Budget', $json, $url);
            foreach ($data['context']['formEngine']['records'] ?? [] as $record) {
                self::assertSame(['table' => 'sys_file_metadata', 'uid' => 7], $record, $url);
            }
            self::assertArrayNotHasKey('newRecord', $data['context']['formEngine'] ?? [], $url);
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function build(array $input): ContextDocument
    {
        $request = CollectionRequest::fromArray($input, new BackendLocationFactory());
        return $this->get(ContextDocumentBuilder::class)->build($request, $GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST']);
    }
}
