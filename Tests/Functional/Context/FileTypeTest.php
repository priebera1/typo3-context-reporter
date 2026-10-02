<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Context;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\ContextDocumentBuilder;
use Priebera\ContextReporter\Context\FileCheck\FileCheckEvaluator;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Context\Tca\TcaInspector;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * File references to files of a type the file field does not allow. The
 * fixture (file_types.csv) adds the files notes.txt [25], CLIP.MP4 [26],
 * README without extension [27], PHOTO.JPG [28], dummy1.pdf [29], photo.jpeg
 * whose index says "pdf" [30] and report.docx whose index says "jpg" [31],
 * and the content elements "Photo set" [65] (images: logo.png, notes.txt,
 * PHOTO.JPG, README), "Media set" [66] (media: CLIP.MP4, notes.txt), "File
 * links" [67] (files: notes.txt, README) and "Text and images" [68] (CType
 * textpic; images: dummy1.pdf, notes.txt, photo.jpeg, report.docx).
 */
final class FileTypeTest extends AbstractContextReporterTestCase
{
    /** May list file references, file mount /user_upload/ */
    private const FILE_TYPE_CHECKER = 15;

    /**
     * TYPO3 removes references to files whose type the field does not allow
     * when the record is saved: the editing form always sends the references
     * of a file field, and DataHandler filters them by the allowed and
     * disallowed file extensions of the field.
     */
    #[Test]
    public function typo3RemovesReferencesOfTypesTheFieldDoesNotAllowOnSave(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/file_types.csv');
        $this->loginBackendUser(self::ADMIN);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tt_content' => [65 => ['header' => 'Photo set', 'image' => '150,151,152,153']]], []);
        $dataHandler->process_datamap();

        self::assertSame([], $dataHandler->errorLog);
        $remaining = [];
        foreach ([150, 151, 152, 153] as $uid) {
            if (BackendUtility::getRecord('sys_file_reference', $uid) !== null) {
                $remaining[] = $uid;
            }
        }
        self::assertSame([150, 152], $remaining, 'notes.txt [151] and README [153] are removed, logo.png and PHOTO.JPG stay');
    }

    /**
     * Image fields allow "common-image-types", which TYPO3 replaces with
     * $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] when it loads the
     * TCA. By default, that list includes PDF (and AI, SVG), which TYPO3 can
     * render as images.
     */
    #[Test]
    public function imageFieldsAllowTheImageFileTypesOfTypo3(): void
    {
        $imageTypes = StringUtility::uniqueList((string)$GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext']);

        self::assertSame($imageTypes, $GLOBALS['TCA']['tt_content']['columns']['image']['config']['allowed'] ?? null, 'TYPO3 resolved the placeholder');
        self::assertSame($imageTypes, $this->get(TcaInspector::class)->getFieldConfiguration('tt_content', 'image', ['CType' => 'textpic'])['allowed'] ?? null);
        self::assertContains('pdf', explode(',', $imageTypes));
        self::assertTrue(FileCheckEvaluator::isAllowedInField('pdf', $this->get(TcaInspector::class)->getFieldConfiguration('tt_content', 'image', ['CType' => 'textpic'])));
    }

    /**
     * The check names exactly the references TYPO3 removes when the record
     * is saved: the type is the extension of the file name, as TYPO3 sees
     * it, not the extension column of the file index.
     */
    #[Test]
    public function checkAgreesWithWhatTypo3RemovesOnSave(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/file_types.csv');
        $this->loginBackendUser(self::ADMIN);

        $reported = array_column($this->fileChecks(['type' => 'record', 'table' => 'tt_content', 'uid' => 68])['references']['problems'] ?? [], 'reference');

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tt_content' => [68 => ['header' => 'Text and images', 'image' => '160,161,162,163']]], []);
        $dataHandler->process_datamap();
        $removed = array_values(array_filter([160, 161, 162, 163], static fn(int $uid): bool => BackendUtility::getRecord('sys_file_reference', $uid) === null));

        self::assertSame([161, 163], $reported, 'notes.txt and report.docx, not dummy1.pdf and photo.jpeg');
        self::assertSame($reported, $removed);
    }

    #[Test]
    public function referencesToFilesOfOtherTypesAreReported(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/file_types.csv');
        $this->loginBackendUser(self::FILE_TYPE_CHECKER);

        self::assertSame(
            [
                'checked' => 4,
                'notChecked' => 0,
                'problems' => [
                    ['field' => 'image', 'fieldLabel' => $this->englishLabel('image'), 'reference' => 151, 'file' => ['uid' => 25, 'name' => 'notes.txt'], 'problems' => ['typeNotAllowed']],
                    ['field' => 'image', 'fieldLabel' => $this->englishLabel('image'), 'reference' => 153, 'file' => ['uid' => 27, 'name' => 'README'], 'problems' => ['typeNotAllowed']],
                ],
            ],
            $this->fileChecks(['type' => 'record', 'table' => 'tt_content', 'uid' => 65])['references'] ?? null,
            'Images: plain text and files without extension are not allowed, PHOTO.JPG is',
        );
        self::assertSame(
            [['field' => 'assets', 'fieldLabel' => $this->englishLabel('assets'), 'reference' => 155, 'file' => ['uid' => 25, 'name' => 'notes.txt'], 'problems' => ['typeNotAllowed']]],
            $this->fileChecks(['type' => 'record', 'table' => 'tt_content', 'uid' => 66])['references']['problems'] ?? null,
            'Media: CLIP.MP4 is allowed',
        );
        self::assertSame([], $this->fileChecks(['type' => 'record', 'table' => 'tt_content', 'uid' => 67])['references']['problems'] ?? null, 'Files: no restriction');
    }

    #[Test]
    public function fieldOverridesOfTheRecordTypeApply(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/file_types.csv');
        $GLOBALS['TCA']['tt_content']['types']['uploads']['columnsOverrides']['media']['config']['disallowed'] = 'txt';
        $GLOBALS['TCA']['tt_content']['types']['textmedia']['columnsOverrides']['assets']['config']['allowed'] = 'jpg,png';
        $this->loginBackendUser(self::ADMIN);

        $fileLinks = $this->fileChecks(['type' => 'record', 'table' => 'tt_content', 'uid' => 67])['references']['problems'] ?? [];
        $media = $this->fileChecks(['type' => 'record', 'table' => 'tt_content', 'uid' => 66])['references']['problems'] ?? [];

        self::assertSame([156], array_column($fileLinks, 'reference'), 'notes.txt is disallowed for "File links", README is not');
        self::assertSame([154, 155], array_column($media, 'reference'), 'Only JPEG and PNG are allowed for "Text & Media"');
    }

    #[Test]
    public function aReportedReferenceIsCheckedWithTheFieldOfItsRecord(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/file_types.csv');
        $GLOBALS['TCA']['tt_content']['types']['uploads']['columnsOverrides']['media']['config']['disallowed'] = 'txt';
        $this->loginBackendUser(self::ADMIN);

        self::assertSame(
            ['typeNotAllowed'],
            $this->fileChecks(['type' => 'record', 'table' => 'sys_file_reference', 'uid' => 156])['references']['problems'][0]['problems'] ?? null,
        );
        self::assertSame([], $this->fileChecks(['type' => 'record', 'table' => 'sys_file_reference', 'uid' => 157])['references']['problems'] ?? null);
    }

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private function fileChecks(array $target): array
    {
        $request = CollectionRequest::fromArray(['source' => 'contextMenu', 'target' => $target], new BackendLocationFactory());
        $document = $this->get(ContextDocumentBuilder::class)->build($request, $GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'])->toArray();
        return $document['context']['fileChecks'] ?? [];
    }

    private function englishLabel(string $field): string
    {
        return $this->get(LanguageServiceFactory::class)->create('en')->sL($GLOBALS['TCA']['tt_content']['columns'][$field]['label']);
    }
}
