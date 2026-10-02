<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Presentation;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Domain\ContextDocument;
use Priebera\ContextReporter\Presentation\FindingsBuilder;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use Priebera\ContextReporter\Tests\Unit\Fixtures\ReportFixture;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * The findings of a report in the viewer's backend language: one list of
 * notices per group (website address, placement, visibility, files,
 * permissions), a small number per group, and one shared note.
 */
final class FindingsBuilderTest extends AbstractContextReporterTestCase
{
    #[Test]
    public function findingsAreGroupedInAFixedOrder(): void
    {
        $findings = $this->build(ReportFixture::documentWithDiagnostics(), 'en');

        self::assertIsArray($findings);
        self::assertSame(['website', 'placement', 'files', 'access'], array_column($findings['groups'], 'key'));
        self::assertSame(['Website address', 'Placement', 'Files', 'Permissions'], array_column($findings['groups'], 'title'));
        self::assertSame(['actions-globe', 'content-container-columns-2', 'actions-file', 'actions-lock'], array_column($findings['groups'], 'icon'));
        self::assertStringStartsWith('These are settings and permissions stored in TYPO3, not a check of the website', $findings['note']);
    }

    #[Test]
    public function websiteAddressFactsExplainTheAddress(): void
    {
        self::assertSame(
            [
                'The website address is in "Français", which is disabled in the site configuration',
                'The page is not translated into "Français": the address uses the page of the default language',
                'Fallback languages of "Français": English, ID 1',
            ],
            $this->group(ReportFixture::documentWithDiagnostics(), 'website', 'en')['items'] ?? null,
        );

        $document = ReportFixture::document();
        $document['context']['routing'] = ['notes' => ['noSite', 'newInWorkspace', 'notPreviewable', 'languageNotInSite', 'baseWithoutHost', 'generationFailed', 'futureNote']];
        $document['context']['page']['doktype'] = 254;
        $document['context']['language'] = ['id' => 5];
        self::assertSame(
            [
                'The page belongs to no site, so it has no website address',
                'The website address belongs to a page that only exists in this workspace',
                'No website address: TYPO3 offers no view for pages of type "Folder" here',
                'No website address: the site has no language with ID 5',
                'The site base has no host: the website address is a path only',
            ],
            $this->group($document, 'website', 'en')['items'] ?? null,
        );
        self::assertSame(1, $this->group($document, 'website', 'en')['more'] ?? null, 'At most five notices per group');
    }

    #[Test]
    public function strictLanguagesHaveNoFallback(): void
    {
        $document = ReportFixture::documentWithDiagnostics();
        $document['context']['routing'] = ['notes' => ['pageNotTranslated'], 'fallback' => ['type' => 'strict']];

        self::assertSame(
            'No fallback language for "Français" (fallback type: strict)',
            $this->group($document, 'website', 'en')['items'][1] ?? null,
        );
    }

    #[Test]
    public function placementFactsNameColumnsAndPages(): void
    {
        self::assertSame(
            [
                'Column 3 is not a column of the backend layout of the page (columns: Main [0], Sidebar [5]). Extensions, e.g. for container elements, can offer further columns.',
                'Page "Home" is set to show the content of page "Shared content" [7] ("Show content from page")',
                'Pages set to show the content of page "Home": Landing [9], 2 without access',
            ],
            $this->group(ReportFixture::documentWithDiagnostics(), 'placement', 'en')['items'] ?? null,
        );

        $document = ReportFixture::documentWithDiagnostics();
        $document['context']['placement'] = ['column' => ['colPos' => 0, 'label' => 'Main', 'inBackendLayout' => true], 'contentFromPage' => ['uid' => 4, 'notAccessible' => true]];
        self::assertSame(
            ['Page "Home" is set to show the content of page 4, which you cannot access ("Show content from page")'],
            $this->group($document, 'placement', 'en')['items'] ?? null,
        );
        $document['context']['placement'] = ['contentFromPage' => ['uid' => 999, 'missing' => true]];
        self::assertSame(
            ['Page "Home" is set to show the content of page 999, which does not exist ("Show content from page")'],
            $this->group($document, 'placement', 'en')['items'] ?? null,
        );
    }

    #[Test]
    public function permissionFactsAreListedWithoutVerdict(): void
    {
        $items = $this->group(ReportFixture::documentWithDiagnostics(), 'access', 'en')['items'] ?? [];

        self::assertSame(
            [
                '"Page Content" is not among the tables you may modify',
                'Your permissions for page "Home" do not include "Edit content"',
                'The record is locked for editing by non-administrators',
                'Language "Français" is not among your languages',
                'Type "Text & Media" is not among your explicitly allowed values',
            ],
            $items,
        );
        self::assertSame(2, $this->group(ReportFixture::documentWithDiagnostics(), 'access', 'en')['more'] ?? null);
        $all = $this->group(ReportFixture::documentWithDiagnostics(), 'access', 'en', null)['items'] ?? [];
        // Field names in the viewer's language from the current TCA
        self::assertSame(
            [
                'Fields you may not edit (exclude fields): ' . $this->englishLabel('tt_content', 'hidden') . ', ' . $this->englishLabel('tt_content', 'layout'),
                'Fields disabled in the page TSconfig: ' . $this->englishLabel('tt_content', 'header'),
            ],
            array_slice($all, 5),
        );
        foreach ($all as $item) {
            self::assertDoesNotMatchRegularExpression('/\b(can|cannot|should be able to) edit\b/i', $item);
        }
    }

    #[Test]
    public function fileFindingsIncludeFileTypes(): void
    {
        $files = $this->group(ReportFixture::documentWithDiagnostics(), 'files', 'en', null)['items'] ?? [];

        self::assertContains('Images: "notes.txt" – File type not allowed in this field, TYPO3 removes the reference when the record is saved', $files);
    }

    #[Test]
    public function fileUsagesAreListedAndCounted(): void
    {
        $document = ReportFixture::fileDocument();
        $document['context']['fileUsage'] = [
            'references' => 5,
            'usages' => [
                ['table' => 'tt_content', 'uid' => 10, 'label' => 'Hero teaser', 'field' => 'image', 'fieldLabel' => 'Images', 'reference' => 160, 'page' => ['uid' => 2, 'title' => 'About'], 'hidden' => true],
                ['table' => 'pages', 'uid' => 2, 'label' => 'About', 'field' => 'media', 'fieldLabel' => 'Files', 'reference' => 162],
            ],
            'notListed' => 1,
            'notAccessible' => 2,
        ];

        self::assertSame(
            [
                'Used in Page Content "Hero teaser" [tt_content:10] · Images · page "About" [2] · reference hidden',
                'Used in Page "About" [pages:2] · ' . $this->englishLabel('pages', 'media'),
                'Further usages: 1',
                'Usages in records you cannot access: 2',
            ],
            $this->group($document, 'files', 'en')['items'] ?? null,
        );
        $document['context']['fileUsage'] = ['references' => 0];
        self::assertSame(['No file references to this file (links in texts are not counted)'], $this->group($document, 'files', 'en')['items'] ?? null);
    }

    #[Test]
    public function findingsFollowTheViewersLanguage(): void
    {
        $findings = $this->build(ReportFixture::documentWithDiagnostics(), 'de');

        self::assertSame(['Webadresse', 'Platzierung', 'Dateien', 'Berechtigungen'], array_column($findings['groups'] ?? [], 'title'));
        self::assertSame('Die Webadresse ist in „Français“, das in der Site-Konfiguration deaktiviert ist', $findings['groups'][0]['items'][0] ?? null);
        self::assertSame('2 weitere in den technischen Details', $findings['groups'][3]['moreText'] ?? null);
    }

    #[Test]
    public function reportsWithoutDiagnosticsHaveNoFindings(): void
    {
        // Reports of 0.1, and a 0.2 visibility section without restricting settings
        self::assertNull($this->build(ReportFixture::document(), 'en'));
        $document = ReportFixture::document();
        $document['context']['visibility'] = ['evaluatedAt' => '2026-10-12T09:30:00+02:00', 'subject' => ['reasons' => []]];
        self::assertNull($this->build($document, 'en'));
    }

    #[Test]
    public function reportsOf020KeepTheirVisibilityAndFileFindings(): void
    {
        $document = ReportFixture::documentWithVisibility();
        $document['context']['fileChecks'] = ReportFixture::documentWithFileChecks()['context']['fileChecks'];

        $findings = $this->build($document, 'en');

        self::assertSame(['visibility', 'files'], array_column($findings['groups'] ?? [], 'key'));
        self::assertSame('Hidden', $findings['groups'][0]['items'][0] ?? null);
    }

    #[Test]
    public function scheduledTimesNameTheServerTimeZoneWhenTheBrowserIsElsewhere(): void
    {
        $document = ReportFixture::documentWithVisibility();
        $document['system']['timeZone'] = 'Europe/Vienna';
        $document['browser']['timeZone'] = 'America/New_York';
        $format = $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] . ' ' . $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'];
        $start = (new \DateTimeImmutable('2026-10-15T08:00:00+02:00'))->format($format);

        self::assertSame('Publishing starts on ' . $start . ' (server time, Europe/Vienna)', $this->group($document, 'visibility', 'en')['items'][1] ?? null);

        $document['browser']['timeZone'] = 'Europe/Vienna';
        self::assertSame('Publishing starts on ' . $start, $this->group($document, 'visibility', 'en')['items'][1] ?? null);
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>|null
     */
    private function build(array $document, string $language, ?int $limit = FindingsBuilder::PRESENTED_PER_GROUP): ?array
    {
        return $this->get(FindingsBuilder::class)->build(ContextDocument::fromArray($document), $this->languageService($language), $limit);
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>|null
     */
    private function group(array $document, string $key, string $language, ?int $limit = FindingsBuilder::PRESENTED_PER_GROUP): ?array
    {
        foreach ($this->build($document, $language, $limit)['groups'] ?? [] as $group) {
            if ($group['key'] === $key) {
                return $group;
            }
        }
        return null;
    }

    private function languageService(string $language): LanguageService
    {
        return $this->get(LanguageServiceFactory::class)->create($language);
    }

    private function englishLabel(string $table, string $field): string
    {
        return $this->languageService('en')->sL($GLOBALS['TCA'][$table]['columns'][$field]['label']);
    }
}
