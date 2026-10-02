<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\ExtensionInfo;
use Priebera\ContextReporter\Export\ContextDetailsFormatter;
use Priebera\ContextReporter\Export\ReportPayloadFactory;
use Priebera\ContextReporter\Tests\Unit\Fixtures\ReportFixture;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ContextDetailsFormatterTest extends UnitTestCase
{
    #[Test]
    public function sectionsAreOrderedAndStructuredValuesAreReadable(): void
    {
        $sections = (new ContextDetailsFormatter())->buildSections($this->payload());

        self::assertSame(
            ['Subject', 'Page', 'Record', 'Editing form', 'Site', 'Language', 'Workspace', 'Backend', 'Reporter', 'Project', 'System', 'Browser'],
            array_column($sections, 'title'),
        );

        $rows = $this->rowsOf($sections, 'Record');
        self::assertSame('tt_content', $rows['Table']);
        self::assertSame('12', $rows['UID']);
        self::assertSame('Text & Media (CType = textmedia)', $rows['Type']);
        self::assertSame('no', $rows['Hidden']);
        self::assertSame('edit: yes', $rows['Access']);

        self::assertSame('Home [1]', $this->rowsOf($sections, 'Page')['Rootline']);
        self::assertSame('tt_content:12', $this->rowsOf($sections, 'Editing form')['Records']);
        self::assertSame('tt_content: header, bodytext', $this->rowsOf($sections, 'Editing form')['Restricted to fields']);
        self::assertSame('Web › Page (web_layout)', $this->rowsOf($sections, 'Backend')['Module']);
        self::assertSame('record_edit (/record/edit)', $this->rowsOf($sections, 'Backend')['Route']);
        self::assertSame('Editors [2], News [5]', $this->rowsOf($sections, 'Reporter')['Groups']);
        self::assertSame('1440×900', $this->rowsOf($sections, 'Browser')['Viewport']);
        self::assertSame('2', $this->rowsOf($sections, 'Browser')['Pixel ratio']);
        self::assertSame('yes', $this->rowsOf($sections, 'System')['Composer mode']);
    }

    #[Test]
    public function fileSectionsAreOrderedAfterTheSubject(): void
    {
        $formatter = new ContextDetailsFormatter();
        $payload = (new ReportPayloadFactory(new ExtensionInfo('1.0.0')))->create(
            ReportFixture::report(document: ReportFixture::fileDocument()),
        );
        $sections = $formatter->buildSections($payload);

        self::assertSame(
            ['Subject', 'File', 'Folder', 'Storage', 'Workspace', 'Backend', 'Reporter', 'Project', 'System', 'Browser'],
            array_column($sections, 'title'),
        );
        $file = $this->rowsOf($sections, 'File');
        self::assertSame('1', $file['Storage UID']);
        self::assertSame('image/png', $file['MIME type']);
        self::assertSame('2048 bytes', $file['Size']);
        self::assertSame('7', $file['Metadata UID']);
        self::assertArrayHasKey('Edit metadata link', $file);
        self::assertSame('1:/user_upload/logo.png', $this->rowsOf($sections, 'Subject')['Identifier']);
        self::assertSame('yes', $this->rowsOf($sections, 'Storage')['Public']);
    }

    #[Test]
    public function plainTextRenderingListsSectionsWithAlignedRows(): void
    {
        $text = (new ContextDetailsFormatter())->toText($this->payload());

        self::assertStringContainsString("Record\n", $text);
        self::assertStringContainsString('  Table: tt_content', $text);
        self::assertStringContainsString('  TYPO3 version: 13.4.35', $text);
        self::assertStringNotContainsString('contentBase64', $text);
    }

    #[Test]
    public function unknownSectionsAndKeysAreHumanized(): void
    {
        $payload = $this->payload();
        $payload['context']['customThing'] = ['someValueName' => 'x', 'nested' => ['a' => 1, 'b' => false]];

        $sections = (new ContextDetailsFormatter())->buildSections($payload);
        $rows = $this->rowsOf($sections, 'Custom thing');

        self::assertSame('x', $rows['Some value name']);
        self::assertSame('a: 1; b: no', $rows['Nested']);
    }

    #[Test]
    public function visibilitySettingsAreDescribedAsReadableFacts(): void
    {
        $payload = (new ReportPayloadFactory(new ExtensionInfo('1.0.0')))->create(
            ReportFixture::report(document: ReportFixture::documentWithVisibility()),
        );
        $sections = (new ContextDetailsFormatter())->buildSections($payload);

        self::assertSame(
            ['Subject', 'Page', 'Record', 'Editing form', 'Site', 'Language', 'Workspace', 'Visibility', 'Backend', 'Reporter', 'Project', 'System', 'Browser'],
            array_column($sections, 'title'),
        );
        self::assertSame([
            'Evaluated at' => '2026-10-12T09:30:00+02:00',
            'Reported object' => 'hidden; scheduled, starts 2026-10-15T08:00:00+02:00; ends 2026-12-31T23:59:00+01:00; workspace: new',
            'Page' => '"Home" [1]: frontend access: Hide at login [-1], Members | *VIP* <b> [2], 2 more; hidden in menus',
            'Parent pages with "Extend to subpages"' => '"Members area" [5]: expired, ended 2026-01-01T00:00:00+01:00 (parent pages without access were not checked)',
            'Translation Deutsch [1]' => 'page: translated; record: hidden',
            'Translation Français [2]' => 'language disabled; page: not translated; record: not translated',
            'Note' => ContextDetailsFormatter::VISIBILITY_NOTE,
        ], $this->rowsOf($sections, 'Visibility'));
        self::assertStringContainsString("Visibility\n  Evaluated at: 2026-10-12T09:30:00+02:00\n  Reported object: hidden;", (new ContextDetailsFormatter())->toText($payload));
    }

    #[Test]
    public function fileChecksAreDescribedAsReadableFacts(): void
    {
        $payload = (new ReportPayloadFactory(new ExtensionInfo('1.0.0')))->create(
            ReportFixture::report(document: ReportFixture::documentWithFileChecks()),
        );
        $sections = (new ContextDetailsFormatter())->buildSections($payload);

        self::assertSame(
            ['Subject', 'Page', 'Record', 'Editing form', 'Site', 'Language', 'Workspace', 'File checks', 'Backend', 'Reporter', 'Project', 'System', 'Browser'],
            array_column($sections, 'title'),
        );
        self::assertSame([
            'File references' => "3 checked, 2 not checked (outside the reporter's file mounts), 4 not checked (limit reached)",
            'Images [image], reference 81' => '"team | *final* <b>.jpg" [sys_file:12]: reference hidden; marked as missing',
            'Media [assets], reference 83' => 'referenced file no longer exists',
            'Further references with problems' => '1',
            'Note' => ContextDetailsFormatter::FILE_CHECKS_NOTE,
        ], $this->rowsOf($sections, 'File checks'));

        $document = ReportFixture::document();
        $document['context']['fileChecks'] = ['file' => ['storageCheck' => 'notFound', 'problems' => ['missing', 'notInStorage', 'storageOffline']]];
        $sections = (new ContextDetailsFormatter())->buildSections(
            (new ReportPayloadFactory(new ExtensionInfo('1.0.0')))->create(ReportFixture::report(document: $document)),
        );
        self::assertSame(
            ['Reported file' => 'not found in its storage; marked as missing; storage offline in the backend', 'Note' => ContextDetailsFormatter::FILE_CHECKS_NOTE],
            $this->rowsOf($sections, 'File checks'),
        );

        // File metadata: the row names the file it stands for
        $document['context']['fileChecks'] = ['file' => ['uid' => 20, 'name' => 'empty.txt', 'storageCheck' => 'found', 'problems' => ['empty']]];
        $sections = (new ContextDetailsFormatter())->buildSections(
            (new ReportPayloadFactory(new ExtensionInfo('1.0.0')))->create(ReportFixture::report(document: $document)),
        );
        self::assertSame(
            ['File "empty.txt" [sys_file:20]' => 'found in its storage; empty (0 bytes)', 'Note' => ContextDetailsFormatter::FILE_CHECKS_NOTE],
            $this->rowsOf($sections, 'File checks'),
        );
    }

    #[Test]
    public function diagnosticsOf030AreReadableRows(): void
    {
        $sections = (new ContextDetailsFormatter())->buildSections(
            (new ReportPayloadFactory(new ExtensionInfo('1.0.0')))->create(ReportFixture::report(document: ReportFixture::documentWithDiagnostics())),
        );

        self::assertSame(
            ['Subject', 'Page', 'Record', 'Editing form', 'Site', 'Language', 'Workspace', 'Website address', 'Placement', 'File checks', 'Permissions', 'Backend', 'Reporter', 'Project', 'System', 'Browser'],
            array_column($sections, 'title'),
        );
        self::assertSame('2026-09-21T16:13:20+02:00', $this->rowsOf($sections, 'Record')['Created']);
        self::assertSame('2026-12-23T05:26:40+01:00', $this->rowsOf($sections, 'Record')['Last changed']);
        self::assertSame('Europe/Vienna', $this->rowsOf($sections, 'System')['Time zone']);
        self::assertSame(
            [
                'Facts' => 'language disabled in the site configuration; page not translated into the language',
                'Fallback' => 'fallback: English [0], ID 1',
                'Note' => ContextDetailsFormatter::ROUTING_NOTE,
            ],
            $this->rowsOf($sections, 'Website address'),
        );
        self::assertSame(
            [
                'Column' => '3 (not a column of the backend layout)',
                'Backend layout columns' => 'Main [0], Sidebar [5]',
                'Backend layout' => 'Two columns (pagets__two), from parent page "Home" [1]',
                'Content from page' => '"Shared content" [7]',
                'Content shown on' => '"Landing" [9], 2 pages without access',
            ],
            $this->rowsOf($sections, 'Placement'),
        );
        self::assertSame(
            'Images [image], reference 84',
            array_keys($this->rowsOf($sections, 'File checks'))[3] ?? null,
        );
        self::assertSame('"notes.txt" [sys_file:14]: file type not allowed in the field', $this->rowsOf($sections, 'File checks')['Images [image], reference 84']);
        self::assertSame(
            [
                'Table' => 'modify: no',
                'Page [1]' => 'show: yes; edit page: no; delete page: no; new pages: no; edit content: no',
                'Edit lock' => 'record',
                'Language' => 'ID 2: not allowed',
                'Record type' => 'CType = textmedia: not allowed',
                'Fields not allowed' => 'Hidden [hidden], Layout [layout]',
                'Fields disabled in TSconfig' => 'Header [header]',
                'Note' => ContextDetailsFormatter::PERMISSIONS_NOTE,
            ],
            $this->rowsOf($sections, 'Permissions'),
        );
    }

    #[Test]
    public function fileUsageAndFilePermissionsAreReadableRows(): void
    {
        $document = ReportFixture::fileDocument();
        $document['context']['fileUsage'] = [
            'references' => 5,
            'usages' => [
                ['table' => 'tt_content', 'uid' => 10, 'label' => 'Hero teaser', 'field' => 'image', 'fieldLabel' => 'Images', 'reference' => 160, 'page' => ['uid' => 2, 'title' => 'About'], 'hidden' => true],
            ],
            'notAccessible' => 4,
        ];
        $document['context']['permissions'] = [
            'fileActions' => ['read' => true, 'write' => false, 'delete' => false],
            'writableFileMount' => false,
            'storageWritable' => true,
        ];
        $sections = (new ContextDetailsFormatter())->buildSections(
            (new ReportPayloadFactory(new ExtensionInfo('1.0.0')))->create(ReportFixture::report(document: $document)),
        );

        self::assertSame(
            [
                'References' => '5',
                '#1' => 'tt_content:10 "Hero teaser", Images [image], page "About" [2], reference 160 (hidden)',
                'Not accessible' => '4',
            ],
            $this->rowsOf($sections, 'File usage'),
        );
        self::assertSame(
            ['File actions' => 'read: yes; write: no; delete: no', 'Writable file mount' => 'no', 'Storage writable' => 'yes', 'Note' => ContextDetailsFormatter::PERMISSIONS_NOTE],
            $this->rowsOf($sections, 'Permissions'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return (new ReportPayloadFactory(new ExtensionInfo('1.0.0')))->create(ReportFixture::report(), '', 'binary');
    }

    /**
     * @param list<array{title: string, rows: array<string, string>}> $sections
     * @return array<string, string>
     */
    private function rowsOf(array $sections, string $title): array
    {
        foreach ($sections as $section) {
            if ($section['title'] === $title) {
                return $section['rows'];
            }
        }
        self::fail('Section ' . $title . ' not found');
    }
}
