<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Context;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\ContextDocumentBuilder;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Context\Subject\SubjectNotAvailableException;
use Priebera\ContextReporter\Domain\ContextDocument;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;

final class FileContextTest extends AbstractContextReporterTestCase
{
    private const FILE_LIST_URL = 'https://backend.example.com/typo3/module/file/list?id=';

    #[Test]
    public function fileReportContainsFalMetadataButNoFileContentOrMetadataValues(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $data = $this->build(['source' => 'fileList', 'target' => ['type' => 'file', 'uid' => self::FILE_LOGO]])->toArray();

        $folderUrl = self::FILE_LIST_URL . '1:/user_upload/';
        self::assertSame([
            'type' => 'file',
            'table' => 'sys_file',
            'uid' => 1,
            'label' => 'logo.png',
            'typeLabel' => 'File',
            'identifier' => '1:/user_upload/logo.png',
            'backendUrl' => $folderUrl,
        ], $data['subject']);
        self::assertSame([
            'uid' => 1,
            'storageUid' => 1,
            'identifier' => '/user_upload/logo.png',
            'name' => 'logo.png',
            'extension' => 'png',
            'mimeType' => 'image/png',
            'size' => 99,
            'metadataUid' => 7,
            'backendUrl' => $folderUrl,
            'editMetadataUrl' => 'https://backend.example.com/typo3/record/edit?edit%5Bsys_file_metadata%5D%5B7%5D=edit',
        ], $data['context']['file']);
        self::assertSame([
            'storageUid' => 1,
            'identifier' => '/user_upload/',
            'name' => 'user_upload',
            'backendUrl' => $folderUrl,
        ], $data['context']['folder']);
        self::assertSame(['uid' => 1, 'name' => 'fileadmin', 'driver' => 'Local', 'public' => true], $data['context']['storage']);
        self::assertArrayNotHasKey('page', $data['context']);
        self::assertArrayNotHasKey('site', $data['context']);
        self::assertArrayNotHasKey('language', $data['context']);
        self::assertSame(
            'File "logo.png" [sys_file:1] (image/png) in 1:/user_upload/ · storage fileadmin · workspace Live',
            $data['summary'],
        );

        $json = json_encode($data, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Confidential', $json);
        self::assertStringNotContainsString(base64_encode((string)file_get_contents($this->instancePath . '/fileadmin/user_upload/logo.png')), $json);
    }

    #[Test]
    public function missingFilesCanBeReported(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $data = $this->build(['source' => 'contextMenu', 'target' => ['type' => 'file', 'uid' => self::FILE_MISSING]])->toArray();

        self::assertSame('manual.pdf', $data['subject']['label']);
        self::assertTrue($data['context']['file']['missing']);
        self::assertArrayNotHasKey('metadataUid', $data['context']['file']);
        self::assertArrayNotHasKey('editMetadataUrl', $data['context']['file']);
    }

    #[Test]
    public function folderReportContainsStorageAndPath(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $data = $this->build(['source' => 'fileList', 'target' => ['type' => 'folder', 'identifier' => '1:/private/']])->toArray();

        $folderUrl = self::FILE_LIST_URL . '1:/private/';
        self::assertSame([
            'type' => 'folder',
            'label' => 'private',
            'typeLabel' => 'Folder',
            'identifier' => '1:/private/',
            'backendUrl' => $folderUrl,
        ], $data['subject']);
        self::assertSame(['storageUid' => 1, 'identifier' => '/private/', 'name' => 'private', 'backendUrl' => $folderUrl], $data['context']['folder']);
        self::assertArrayNotHasKey('file', $data['context']);
        self::assertSame('fileadmin', $data['context']['storage']['name']);
        self::assertSame('Folder "private" [1:/private/] · storage fileadmin · workspace Live', $data['summary']);
        self::assertStringNotContainsString('budget', json_encode($data, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function storageRootIsNamedAfterTheStorage(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $data = $this->build(['source' => 'contextMenu', 'target' => ['type' => 'folder', 'identifier' => '1:/']])->toArray();

        self::assertSame('fileadmin', $data['subject']['label']);
        self::assertSame('1:/', $data['subject']['identifier']);
        self::assertSame('/', $data['context']['folder']['identifier']);
    }

    #[Test]
    public function editorCanReportFilesAndFoldersInTheirFileMount(): void
    {
        $this->loginBackendUser(self::EDITOR);

        self::assertSame('logo.png', $this->build(['source' => 'fileList', 'target' => ['type' => 'file', 'uid' => self::FILE_LOGO]])->getSubjectLabel());
        self::assertSame('user_upload', $this->build(['source' => 'fileList', 'target' => ['type' => 'folder', 'identifier' => '1:/user_upload/']])->getSubjectLabel());
    }

    #[Test]
    public function editorCannotReportFilesOrFoldersOutsideTheirFileMount(): void
    {
        $this->loginBackendUser(self::EDITOR);

        foreach ([
            ['type' => 'file', 'uid' => self::FILE_BUDGET],
            ['type' => 'file', 'uid' => 999],
            ['type' => 'folder', 'identifier' => '1:/private/'],
            ['type' => 'folder', 'identifier' => '1:/'],
            ['type' => 'folder', 'identifier' => '1:/user_upload/does-not-exist/'],
            ['type' => 'folder', 'identifier' => '1:/user_upload/../private/'],
            ['type' => 'folder', 'identifier' => '9:/'],
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
    public function toolbarReportInTheFileListUsesTheOpenFolder(): void
    {
        $this->loginBackendUser(self::EDITOR);

        $data = $this->build([
            'source' => 'toolbar',
            'location' => ['url' => '/typo3/module/file/list?token=secret&id=1%3A%2Fuser_upload%2F', 'module' => 'media_management'],
        ])->toArray();

        self::assertSame('folder', $data['subject']['type']);
        self::assertSame('1:/user_upload/', $data['subject']['identifier']);
        self::assertSame('media_management', $data['context']['backend']['module']['identifier']);
        self::assertArrayNotHasKey('parameters', $data['context']['backend']);
        self::assertSame(
            'Folder "user_upload" [1:/user_upload/] · storage fileadmin · workspace Live · module ' . self::coreModuleLabel('media_management'),
            $data['summary'],
        );
    }

    #[Test]
    public function toolbarReportInTheFileListIgnoresInaccessibleFolders(): void
    {
        $this->loginBackendUser(self::EDITOR);

        $data = $this->build([
            'source' => 'toolbar',
            'location' => ['url' => '/typo3/module/file/list?id=1%3A%2Fprivate%2F', 'module' => 'media_management'],
        ])->toArray();

        self::assertSame('backend', $data['subject']['type']);
        self::assertArrayNotHasKey('folder', $data['context']);
        self::assertArrayNotHasKey('storage', $data['context']);
        self::assertStringNotContainsString('private', json_encode($data, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function folderIdentifiersAreIgnoredOutsideOfFolderTreeModules(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $data = $this->build([
            'source' => 'toolbar',
            'location' => ['url' => '/typo3/module/site/configuration?id=1%3A%2Fuser_upload%2F', 'module' => 'site_configuration'],
        ])->toArray();

        self::assertSame('backend', $data['subject']['type']);
        self::assertArrayNotHasKey('folder', $data['context']);
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
