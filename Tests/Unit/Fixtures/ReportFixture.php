<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Fixtures;

use Priebera\ContextReporter\Domain\AttachmentMetadata;
use Priebera\ContextReporter\Domain\ContextDocument;
use Priebera\ContextReporter\Domain\Report;
use Priebera\ContextReporter\Domain\ReportSource;

/**
 * A realistic report about a content element opened in the editing form.
 */
final class ReportFixture
{
    public const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

    /**
     * @return array<string, mixed>
     */
    public static function document(): array
    {
        return [
            'summary' => 'Content Element "Hero teaser" [tt_content:12] on page "Home" [1] · site main · language English · workspace Live',
            'subject' => [
                'type' => 'record',
                'table' => 'tt_content',
                'uid' => 12,
                'label' => 'Hero teaser',
                'typeLabel' => 'Content Element',
                'backendUrl' => 'https://example.com/typo3/record/edit?edit%5Btt_content%5D%5B12%5D=edit',
            ],
            'project' => [
                'name' => 'Agency Portal',
                'identifier' => 'agency-portal',
                'environment' => 'Production',
                'backendUrl' => 'https://example.com/typo3/',
            ],
            'reporter' => [
                'uid' => 3,
                'username' => 'editor',
                'realName' => 'Erika Editor',
                'email' => 'erika@example.com',
                'admin' => false,
                'groups' => [['uid' => 2, 'title' => 'Editors'], ['uid' => 5, 'title' => 'News']],
            ],
            'context' => [
                'backend' => [
                    'module' => ['identifier' => 'web_layout', 'title' => 'Page', 'group' => 'Web'],
                    'route' => ['identifier' => 'record_edit', 'path' => '/record/edit'],
                    'backendLanguage' => 'de',
                ],
                'page' => [
                    'uid' => 1,
                    'pid' => 0,
                    'title' => 'Home',
                    'slug' => '/',
                    'doktype' => 1,
                    'doktypeLabel' => 'Standard',
                    'hidden' => false,
                    'rootline' => [['uid' => 1, 'title' => 'Home']],
                    'backendUrl' => 'https://example.com/typo3/module/web/layout?id=1',
                    'frontendUrl' => 'https://example.com/',
                ],
                'record' => [
                    'table' => 'tt_content',
                    'tableTitle' => 'Page Content',
                    'uid' => 12,
                    'pid' => 1,
                    'label' => 'Hero teaser',
                    'type' => ['field' => 'CType', 'value' => 'textmedia', 'label' => 'Text & Media'],
                    'languageId' => 0,
                    'colPos' => 0,
                    'hidden' => false,
                    'access' => ['edit' => true],
                    'backendUrl' => 'https://example.com/typo3/record/edit?edit%5Btt_content%5D%5B12%5D=edit',
                ],
                'formEngine' => [
                    'mode' => 'edit',
                    'records' => [['table' => 'tt_content', 'uid' => 12]],
                    'columnsOnly' => ['tt_content' => ['header', 'bodytext']],
                ],
                'site' => ['identifier' => 'main', 'base' => 'https://example.com/', 'rootPageId' => 1],
                'language' => ['id' => 0, 'title' => 'English', 'locale' => 'en-US'],
                'workspace' => ['id' => 0, 'title' => 'Live'],
            ],
            'system' => [
                'typo3Version' => '13.4.35',
                'phpVersion' => '8.2.33',
                'applicationContext' => 'Production',
                'composerMode' => true,
            ],
            'browser' => [
                'summary' => 'Chrome 128 · macOS · 1440×900 @2x',
                'name' => 'Chrome',
                'version' => '128',
                'os' => 'macOS',
                'userAgent' => self::USER_AGENT,
                'language' => 'de-AT',
                'viewport' => ['width' => 1440, 'height' => 900],
                'devicePixelRatio' => 2.0,
            ],
        ];
    }

    /**
     * A report about a file opened in the file list.
     *
     * @return array<string, mixed>
     */
    public static function fileDocument(): array
    {
        $document = self::document();
        $document['summary'] = 'File "logo.png" [sys_file:21] (image/png) in 1:/user_upload/ · storage fileadmin · workspace Live · module File › Filelist';
        $document['subject'] = [
            'type' => 'file',
            'table' => 'sys_file',
            'uid' => 21,
            'label' => 'logo.png',
            'typeLabel' => 'File',
            'identifier' => '1:/user_upload/logo.png',
            'backendUrl' => 'https://example.com/typo3/module/file/list?id=1:/user_upload/',
        ];
        $document['context'] = [
            'backend' => [
                'module' => ['identifier' => 'media_management', 'title' => 'Filelist', 'group' => 'File'],
                'route' => ['identifier' => 'media_management', 'path' => '/module/file/list'],
                'backendLanguage' => 'en',
            ],
            'file' => [
                'uid' => 21,
                'storageUid' => 1,
                'identifier' => '/user_upload/logo.png',
                'name' => 'logo.png',
                'extension' => 'png',
                'mimeType' => 'image/png',
                'size' => 2048,
                'metadataUid' => 7,
                'backendUrl' => 'https://example.com/typo3/module/file/list?id=1:/user_upload/',
                'editMetadataUrl' => 'https://example.com/typo3/record/edit?edit%5Bsys_file_metadata%5D%5B7%5D=edit',
            ],
            'folder' => [
                'storageUid' => 1,
                'identifier' => '/user_upload/',
                'name' => 'user_upload',
                'backendUrl' => 'https://example.com/typo3/module/file/list?id=1:/user_upload/',
            ],
            'storage' => ['uid' => 1, 'name' => 'fileadmin', 'driver' => 'Local', 'public' => true],
            'workspace' => ['id' => 0, 'title' => 'Live'],
        ];
        return $document;
    }

    /**
     * @param array<string, mixed>|null $document
     */
    public static function report(bool $withScreenshot = true, string $description = "The image selector shows no files.\nIt worked yesterday.", ?array $document = null): Report
    {
        return new Report(
            identifier: 'CR-7K3Q-9XMA-2B4F',
            createdAt: new \DateTimeImmutable('2026-09-16T10:20:30+00:00'),
            reporterUid: 3,
            source: ReportSource::FormEngine,
            title: 'Cannot select an image',
            description: $description,
            document: ContextDocument::fromArray($document ?? self::document()),
            screenshot: $withScreenshot
                ? new AttachmentMetadata('screenshot', 'CR-7K3Q-9XMA-2B4F-screenshot.png', 'image/png', 99, 3, 2, str_repeat('a', 64))
                : null,
            uid: 5,
        );
    }
}
