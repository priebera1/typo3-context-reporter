<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Context;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\ContextDocumentBuilder;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Domain\ContextDocument;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * "context.fileChecks": file problems TYPO3 knows about, within the
 * reporter's access. The fixture (file_checks.csv) adds an offline storage
 * "Archive" [2], files that are empty [20], gone from the disk [21], marked as
 * missing although they exist [22], in the offline storage [23] or without a
 * size in the index [24], and the content elements "Gallery" [60] (CType
 * image) with references that are fine [101], hidden [102], to a missing file
 * [103], to a file that no longer exists [104], to a file outside the editors'
 * file mount [105], to the offline storage [106], to an empty file [107], in
 * a field the type does not show [108] and deleted [109]; its translation [61],
 * 12 hidden media references [62], old references of a text element [63], an
 * element for many references [64] and a hidden media reference of the page
 * "About" [130]. Workspace A adds [140], hides [101] and deletes [107].
 * File metadata describes the empty file [30, translation 31], the file gone
 * from the disk [32] and the file outside the mount [33].
 * Fiona [8] may list file references and edit page media, Nora [9] may not
 * list file references and Mia [10] may not edit page media.
 */
final class FileChecksTest extends AbstractContextReporterTestCase
{
    private const FILE_CHECKER = 8;
    private const NO_REFERENCES = 9;
    private const NO_PAGE_MEDIA = 10;
    private const WORKSPACE_A = 1;

    private const GALLERY = 60;
    private const GALLERY_TRANSLATION = 61;
    private const HIDDEN_MEDIA = 62;
    private const OLD_IMAGES = 63;
    private const MANY_MEDIA = 64;

    private const FILE_EMPTY = 20;
    private const FILE_GONE = 21;
    private const FILE_MARKED_MISSING = 22;
    private const FILE_OFFLINE = 23;
    private const FILE_WITHOUT_SIZE = 24;

    private const METADATA_LOGO = 7;
    private const METADATA_EMPTY = 30;
    private const METADATA_EMPTY_TRANSLATION = 31;
    private const METADATA_GONE = 32;
    private const METADATA_OUTSIDE_MOUNT = 33;

    protected array $coreExtensionsToLoad = ['typo3/cms-filelist', 'typo3/cms-workspaces'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/workspaces.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/file_checks.csv');
        $fileadmin = $this->instancePath . '/fileadmin/user_upload/';
        file_put_contents($fileadmin . 'empty.txt', '');
        file_put_contents($fileadmin . 'unknown-size.txt', "Size not indexed yet\n");
        copy($fileadmin . 'logo.png', $fileadmin . 'found.png');
        GeneralUtility::mkdir_deep($this->instancePath . '/archive');
        copy($fileadmin . 'logo.png', $this->instancePath . '/archive/offline.png');
    }

    #[Test]
    public function referencesAreCheckedWithinTheReportersAccess(): void
    {
        $this->loginBackendUser(self::FILE_CHECKER);
        $label = $this->englishLabel('tt_content', 'image');

        $document = $this->build(['type' => 'record', 'table' => 'tt_content', 'uid' => self::GALLERY]);

        self::assertSame([
            'references' => [
                // The file outside the file mount [105] and the one in a storage without access [106]
                'checked' => 5,
                'notChecked' => 2,
                'problems' => [
                    ['field' => 'image', 'fieldLabel' => $label, 'reference' => 102, 'file' => ['uid' => self::FILE_LOGO, 'name' => 'logo.png'], 'problems' => ['hidden']],
                    ['field' => 'image', 'fieldLabel' => $label, 'reference' => 103, 'file' => ['uid' => self::FILE_MISSING, 'name' => 'manual.pdf'], 'problems' => ['missing']],
                    ['field' => 'image', 'fieldLabel' => $label, 'reference' => 104, 'problems' => ['brokenReference']],
                    // Plain text is no image type
                    ['field' => 'image', 'fieldLabel' => $label, 'reference' => 107, 'file' => ['uid' => self::FILE_EMPTY, 'name' => 'empty.txt'], 'problems' => ['empty', 'typeNotAllowed']],
                ],
            ],
        ], $document->getContextSection('fileChecks'));

        $json = json_encode($document->toArray(), JSON_THROW_ON_ERROR);
        foreach (['budget', '/private/', 'offline.png', 'Archive', '"uid":2,"name"', '"uid":23'] as $inaccessible) {
            self::assertStringNotContainsString($inaccessible, $json);
        }
    }

    #[Test]
    public function administratorsGetAllReferencedFilesChecked(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $references = $this->fileChecks(['type' => 'record', 'table' => 'tt_content', 'uid' => self::GALLERY])['references'];

        self::assertSame(7, $references['checked']);
        self::assertSame(0, $references['notChecked']);
        // Neither the old reference in "assets" [108] nor the deleted one [109]
        self::assertSame([102, 103, 104, 105, 106, 107], array_column($references['problems'], 'reference'));
        self::assertSame(
            ['field' => 'image', 'fieldLabel' => $this->englishLabel('tt_content', 'image'), 'reference' => 105, 'file' => ['uid' => self::FILE_BUDGET, 'name' => 'budget.txt'], 'problems' => ['typeNotAllowed']],
            $references['problems'][3],
        );
        self::assertSame(
            ['field' => 'image', 'fieldLabel' => $this->englishLabel('tt_content', 'image'), 'reference' => 106, 'file' => ['uid' => self::FILE_OFFLINE, 'name' => 'offline.png'], 'problems' => ['storageOffline']],
            $references['problems'][4],
        );
    }

    #[Test]
    public function onlyFileFieldsOfTheRecordTypeAreChecked(): void
    {
        $this->loginBackendUser(self::ADMIN);

        // A text element does not show "image" or "assets": its old references are not checked
        self::assertArrayNotHasKey('fileChecks', $this->build(['type' => 'record', 'table' => 'tt_content', 'uid' => self::OLD_IMAGES])->toArray()['context']);
        // Text & Media shows "assets"
        self::assertSame(12, $this->fileChecks(['type' => 'record', 'table' => 'tt_content', 'uid' => self::HIDDEN_MEDIA])['references']['checked']);
    }

    #[Test]
    public function referencesNeedPermissionForFileReferencesAndTheField(): void
    {
        $this->loginBackendUser(self::NO_REFERENCES);
        self::assertArrayNotHasKey('fileChecks', $this->build(['type' => 'record', 'table' => 'tt_content', 'uid' => self::GALLERY])->toArray()['context']);
        self::assertArrayNotHasKey('fileChecks', $this->build(['type' => 'page', 'uid' => 2])->toArray()['context']);

        // "Media" of pages is an exclude field
        $this->loginBackendUser(self::NO_PAGE_MEDIA);
        self::assertArrayNotHasKey('fileChecks', $this->build(['type' => 'page', 'uid' => 2])->toArray()['context']);
        self::assertSame(5, $this->fileChecks(['type' => 'record', 'table' => 'tt_content', 'uid' => self::GALLERY])['references']['checked']);

        $this->loginBackendUser(self::FILE_CHECKER);
        self::assertSame(
            [
                'checked' => 1,
                'notChecked' => 0,
                'problems' => [['field' => 'media', 'fieldLabel' => $this->englishLabel('pages', 'media'), 'reference' => 130, 'file' => ['uid' => self::FILE_LOGO, 'name' => 'logo.png'], 'problems' => ['hidden']]],
            ],
            $this->fileChecks(['type' => 'page', 'uid' => 2])['references'],
        );
    }

    #[Test]
    public function referencesAreCheckedInTheCurrentWorkspace(): void
    {
        $this->loginInWorkspace(self::ADMIN, self::WORKSPACE_A);

        $references = $this->fileChecks(['type' => 'record', 'table' => 'tt_content', 'uid' => self::GALLERY])['references'];

        // [101] is hidden in the workspace, [107] deleted and [140] new
        self::assertSame(7, $references['checked']);
        self::assertSame([101, 102, 103, 104, 105, 106, 140], array_column($references['problems'], 'reference'));
        self::assertSame(['hidden'], $references['problems'][0]['problems']);
        self::assertSame(['missing'], $references['problems'][6]['problems']);

        $this->loginInWorkspace(self::ADMIN, 0);
        self::assertSame([102, 103, 104, 105, 106, 107], array_column($this->fileChecks(['type' => 'record', 'table' => 'tt_content', 'uid' => self::GALLERY])['references']['problems'], 'reference'));
    }

    #[Test]
    public function translatedRecordsHaveTheirOwnReferences(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $references = $this->fileChecks(['type' => 'record', 'table' => 'tt_content', 'uid' => self::GALLERY_TRANSLATION])['references'];

        self::assertSame(1, $references['checked']);
        self::assertSame([110], array_column($references['problems'], 'reference'));
        self::assertSame(['brokenReference'], $references['problems'][0]['problems']);
    }

    #[Test]
    public function listedProblemsAndCheckedReferencesAreCapped(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $hidden = $this->fileChecks(['type' => 'record', 'table' => 'tt_content', 'uid' => self::HIDDEN_MEDIA])['references'];
        self::assertSame(12, $hidden['checked']);
        self::assertCount(10, $hidden['problems']);
        self::assertSame(2, $hidden['problemsNotListed']);

        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference');
        for ($sorting = 1; $sorting <= 102; $sorting++) {
            $connection->insert('sys_file_reference', [
                'pid' => 2,
                'uid_local' => self::FILE_LOGO,
                'uid_foreign' => self::MANY_MEDIA,
                'tablenames' => 'tt_content',
                'fieldname' => 'assets',
                'sorting_foreign' => $sorting,
            ]);
        }
        self::assertSame(
            ['checked' => 100, 'notChecked' => 0, 'problems' => [], 'overLimit' => 2],
            $this->fileChecks(['type' => 'record', 'table' => 'tt_content', 'uid' => self::MANY_MEDIA])['references'],
        );
    }

    #[Test]
    public function reportedFilesAreLookedUpInTheirStorage(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $expected = [
            self::FILE_LOGO => ['storageCheck' => 'found', 'problems' => []],
            self::FILE_GONE => ['storageCheck' => 'notFound', 'problems' => ['missing', 'notInStorage']],
            self::FILE_MISSING => ['storageCheck' => 'notFound', 'problems' => ['missing', 'notInStorage']],
            self::FILE_MARKED_MISSING => ['storageCheck' => 'found', 'problems' => ['missing']],
            self::FILE_OFFLINE => ['storageCheck' => 'notChecked', 'problems' => ['storageOffline']],
            self::FILE_EMPTY => ['storageCheck' => 'found', 'problems' => ['empty']],
            self::FILE_WITHOUT_SIZE => ['storageCheck' => 'found', 'problems' => []],
        ];
        foreach ($expected as $uid => $checks) {
            self::assertSame(['file' => $checks], $this->fileChecks(['type' => 'file', 'uid' => $uid]), 'sys_file:' . $uid);
        }

        $this->loginBackendUser(self::FILE_CHECKER);
        self::assertSame(['file' => $expected[self::FILE_EMPTY]], $this->fileChecks(['type' => 'file', 'uid' => self::FILE_EMPTY]));
    }

    #[Test]
    public function fileMetadataIsCheckedLikeItsFile(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $empty = ['uid' => self::FILE_EMPTY, 'name' => 'empty.txt', 'storageCheck' => 'found', 'problems' => ['empty']];

        // The report stays about the metadata; its file gets the checks of a reported file
        $document = $this->build(['type' => 'record', 'table' => 'sys_file_metadata', 'uid' => self::METADATA_EMPTY])->toArray();
        self::assertSame(['sys_file_metadata', self::METADATA_EMPTY], [$document['subject']['table'], $document['subject']['uid']]);
        self::assertSame(['file' => $empty], $document['context']['fileChecks']);
        // The translation describes the same file
        self::assertSame(['file' => $empty], $this->fileChecks(['type' => 'record', 'table' => 'sys_file_metadata', 'uid' => self::METADATA_EMPTY_TRANSLATION]));
        self::assertSame(
            ['file' => ['uid' => self::FILE_GONE, 'name' => 'gone.png', 'storageCheck' => 'notFound', 'problems' => ['missing', 'notInStorage']]],
            $this->fileChecks(['type' => 'record', 'table' => 'sys_file_metadata', 'uid' => self::METADATA_GONE]),
        );
        // Opened with "Edit metadata" in the file list
        $form = $this->buildFromInput([
            'source' => 'formEngine',
            'location' => ['url' => '/typo3/record/edit?edit%5Bsys_file_metadata%5D%5B' . self::METADATA_EMPTY . '%5D=edit'],
        ]);
        self::assertSame('sys_file_metadata', $form->getSubject()['table'] ?? '');
        self::assertSame(['file' => $empty], $form->getContextSection('fileChecks'));

        self::assertSame(['Empty file (0 bytes)'], $this->findingNotices($this->build(['type' => 'record', 'table' => 'sys_file_metadata', 'uid' => self::METADATA_EMPTY]), 'files'));
        // Healthy file: checked, nothing to point out
        self::assertSame(
            ['file' => ['uid' => self::FILE_LOGO, 'name' => 'logo.png', 'storageCheck' => 'found', 'problems' => []]],
            $this->fileChecks(['type' => 'record', 'table' => 'sys_file_metadata', 'uid' => self::METADATA_LOGO]),
        );
        self::assertNull($this->findingNotices($this->build(['type' => 'record', 'table' => 'sys_file_metadata', 'uid' => self::METADATA_LOGO]), 'files'));
        // The file itself is still reported as before
        self::assertSame(['file' => ['storageCheck' => 'found', 'problems' => ['empty']]], $this->fileChecks(['type' => 'file', 'uid' => self::FILE_EMPTY]));

        $this->loginBackendUser(self::FILE_CHECKER);
        self::assertSame(['file' => $empty], $this->fileChecks(['type' => 'record', 'table' => 'sys_file_metadata', 'uid' => self::METADATA_EMPTY]));
    }

    #[Test]
    public function metadataOfInaccessibleFilesRevealsNothingAboutTheFile(): void
    {
        $this->loginBackendUser(self::FILE_CHECKER);

        $document = $this->buildFromInput([
            'source' => 'formEngine',
            'location' => ['url' => '/typo3/record/edit?edit%5Bsys_file_metadata%5D%5B' . self::METADATA_OUTSIDE_MOUNT . '%5D=edit', 'pageTreeSelection' => '2'],
        ])->toArray();

        self::assertNotSame('sys_file_metadata', $document['subject']['table'] ?? '');
        self::assertArrayNotHasKey('file', $document['context']['fileChecks'] ?? []);
        $json = json_encode($document, JSON_THROW_ON_ERROR);
        foreach (['budget', 'Budget', '/private/', '"uid":' . self::FILE_BUDGET . ',"name"'] as $inaccessible) {
            self::assertStringNotContainsString($inaccessible, $json);
        }
    }

    #[Test]
    public function reportedFileReferencesAreCheckedLikeTheReferencesOfARecord(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $label = $this->englishLabel('tt_content', 'image');

        self::assertSame(
            ['references' => ['checked' => 1, 'notChecked' => 0, 'problems' => [
                ['field' => 'image', 'fieldLabel' => $label, 'reference' => 103, 'file' => ['uid' => self::FILE_MISSING, 'name' => 'manual.pdf'], 'problems' => ['missing']],
            ]]],
            $this->fileChecks(['type' => 'record', 'table' => 'sys_file_reference', 'uid' => 103]),
        );
        self::assertSame(
            ['references' => ['checked' => 1, 'notChecked' => 0, 'problems' => [['field' => 'image', 'fieldLabel' => $label, 'reference' => 104, 'problems' => ['brokenReference']]]]],
            $this->fileChecks(['type' => 'record', 'table' => 'sys_file_reference', 'uid' => 104]),
        );
        self::assertSame(
            ['references' => ['checked' => 1, 'notChecked' => 0, 'problems' => []]],
            $this->fileChecks(['type' => 'record', 'table' => 'sys_file_reference', 'uid' => 101]),
        );

        // A reference on an accessible page to a file outside the file mount: only counted
        $this->loginBackendUser(self::FILE_CHECKER);
        $document = $this->build(['type' => 'record', 'table' => 'sys_file_reference', 'uid' => 105]);
        self::assertSame(['references' => ['checked' => 0, 'notChecked' => 1, 'problems' => []]], $document->getContextSection('fileChecks'));
        self::assertSame(
            ['Nicht geprüfte referenzierte Dateien (außerhalb der zugänglichen Dateifreigaben): 1'],
            $this->findingNotices($document, 'files'),
        );
        // The label of a file reference is the name of its file
        $json = json_encode($document->toArray(), JSON_THROW_ON_ERROR);
        foreach (['budget', '/private/', '"uid":' . self::FILE_BUDGET . ',"name"'] as $inaccessible) {
            self::assertStringNotContainsString($inaccessible, $json);
        }
        self::assertSame('File Reference [sys_file_reference:105] on page "About" [2] · site main · language English · workspace Live', $document->toArray()['summary']);

        $this->loginBackendUser(self::ADMIN);
        self::assertSame('budget.txt', $this->build(['type' => 'record', 'table' => 'sys_file_reference', 'uid' => 105])->getSubject()['label'] ?? null);
    }

    #[Test]
    public function otherSubjectsHaveNoFileChecks(): void
    {
        $this->loginBackendUser(self::ADMIN);

        self::assertArrayNotHasKey('fileChecks', $this->build(['type' => 'folder', 'identifier' => '1:/user_upload/'])->toArray()['context']);
        self::assertArrayNotHasKey('fileChecks', $this->build(['type' => 'record', 'table' => 'tt_content', 'uid' => 10])->toArray()['context']);
        $newRecord = $this->buildFromInput([
            'source' => 'formEngine',
            'location' => ['url' => '/typo3/record/edit?edit[tt_content][2]=new&defVals[tt_content][CType]=image'],
        ])->toArray();
        self::assertTrue($newRecord['subject']['isNew'] ?? false);
        self::assertArrayNotHasKey('fileChecks', $newRecord['context']);
    }

    #[Test]
    public function noticesDescribeTheProblemsInTheViewersLanguage(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $images = $this->viewerLabel('tt_content', 'image');
        $notAllowed = 'File type not allowed in this field, TYPO3 removes the reference when the record is saved';

        self::assertSame(
            [
                $images . ': "logo.png" – Reference hidden',
                $images . ': "manual.pdf" – Marked as missing',
                $images . ': Referenced file no longer exists',
                $images . ': "budget.txt" – ' . $notAllowed,
                $images . ': "offline.png" – Storage offline in the backend',
                $images . ': "empty.txt" – Empty file (0 bytes) · ' . $notAllowed,
            ],
            $this->findingNotices($this->build(['type' => 'record', 'table' => 'tt_content', 'uid' => self::GALLERY]), 'files'),
        );
        self::assertSame(
            'Further references with problems: 2',
            array_slice($this->findingNotices($this->build(['type' => 'record', 'table' => 'tt_content', 'uid' => self::HIDDEN_MEDIA]), 'files') ?? [], -1)[0] ?? '',
        );

        // Reported files: the checks, followed by where the file is used
        $unused = 'No file references to this file (links in texts are not counted)';
        $usedInGallery = 'Used in Page Content "Gallery" [tt_content:60] · ' . $images . ' · page "About" [2]';
        self::assertSame(['Not found in its storage', $unused], $this->findingNotices($this->build(['type' => 'file', 'uid' => self::FILE_GONE]), 'files'));
        self::assertSame(['Marked as missing, but found in its storage', $unused], $this->findingNotices($this->build(['type' => 'file', 'uid' => self::FILE_MARKED_MISSING]), 'files'));
        self::assertSame(['Storage offline in the backend', $usedInGallery], $this->findingNotices($this->build(['type' => 'file', 'uid' => self::FILE_OFFLINE]), 'files'));
        self::assertSame(['Empty file (0 bytes)', $usedInGallery], $this->findingNotices($this->build(['type' => 'file', 'uid' => self::FILE_EMPTY]), 'files'));
        // Nothing to point out about the file itself
        foreach ($this->findingNotices($this->build(['type' => 'file', 'uid' => self::FILE_LOGO]), 'files') ?? [] as $notice) {
            self::assertMatchesRegularExpression('/^(Used in |Further usages: )/', $notice);
        }

        // Fiona works in German; files she may not access are only counted
        $this->loginBackendUser(self::FILE_CHECKER);
        $images = $this->viewerLabel('tt_content', 'image');
        self::assertSame(
            [
                $images . ': „logo.png“ – Referenz verborgen',
                $images . ': „manual.pdf“ – Als fehlend markiert',
                $images . ': Referenzierte Datei existiert nicht mehr',
                $images . ': „empty.txt“ – Leere Datei (0 Bytes) · Dateityp in diesem Feld nicht erlaubt, TYPO3 entfernt die Referenz beim Speichern des Datensatzes',
                'Nicht geprüfte referenzierte Dateien (außerhalb der zugänglichen Dateifreigaben): 2',
            ],
            $this->findingNotices($this->build(['type' => 'record', 'table' => 'tt_content', 'uid' => self::GALLERY]), 'files'),
        );
    }

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private function fileChecks(array $target): array
    {
        $checks = $this->build($target)->getContextSection('fileChecks');
        self::assertNotSame([], $checks);
        return $checks;
    }

    /**
     * @param array<string, mixed> $target
     */
    private function build(array $target): ContextDocument
    {
        return $this->buildFromInput(['source' => 'contextMenu', 'target' => $target]);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function buildFromInput(array $input): ContextDocument
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
     * The field label as reports store it (English)
     */
    private function englishLabel(string $table, string $field): string
    {
        $label = $this->get(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class)->create('en')->sL($GLOBALS['TCA'][$table]['columns'][$field]['label']);
        self::assertNotSame('', $label);
        return $label;
    }

    /**
     * The field label in the language of the logged-in user
     */
    private function viewerLabel(string $table, string $field): string
    {
        return $GLOBALS['LANG']->sL($GLOBALS['TCA'][$table]['columns'][$field]['label']);
    }
}
