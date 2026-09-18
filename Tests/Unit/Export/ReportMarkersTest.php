<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\ExtensionInfo;
use Priebera\ContextReporter\Export\ContextDetailsFormatter;
use Priebera\ContextReporter\Export\ReportMarkers;
use Priebera\ContextReporter\Export\ReportPayloadFactory;
use Priebera\ContextReporter\Tests\Unit\Fixtures\ReportFixture;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ReportMarkersTest extends UnitTestCase
{
    #[Test]
    public function markersCoverAllDocumentedFamilies(): void
    {
        $markers = $this->createMarkers(ReportFixture::report());

        self::assertSame('CR-7K3Q-9XMA-2B4F', $markers['report.id']);
        self::assertSame('Cannot select an image', $markers['report.title']);
        self::assertSame("The image selector shows no files.\nIt worked yesterday.", $markers['report.description']);
        self::assertSame('2026-09-16 10:20 UTC', $markers['report.createdAt']);
        self::assertSame('Record editing form', $markers['report.source']);
        self::assertSame('https://example.com/typo3/report', $markers['report.url']);

        self::assertSame('Agency Portal', $markers['project.name']);
        self::assertSame('agency-portal', $markers['project.identifier']);
        self::assertSame('Production', $markers['project.environment']);
        self::assertSame('https://example.com/typo3/', $markers['project.url']);

        self::assertSame('Erika Editor', $markers['reporter.name']);
        self::assertSame('editor', $markers['reporter.username']);
        self::assertSame('erika@example.com', $markers['reporter.email']);
        self::assertSame('3', $markers['reporter.uid']);
        self::assertSame('Editors, News', $markers['reporter.groups']);

        self::assertSame('1', $markers['page.uid']);
        self::assertSame('Home', $markers['page.title']);
        self::assertSame('/', $markers['page.slug']);
        self::assertSame('https://example.com/', $markers['page.url']);
        self::assertSame('https://example.com/typo3/module/web/layout?id=1', $markers['page.backendUrl']);

        self::assertSame('tt_content', $markers['record.table']);
        self::assertSame('Page Content', $markers['record.tableTitle']);
        self::assertSame('12', $markers['record.uid']);
        self::assertSame('Hero teaser', $markers['record.label']);
        self::assertSame('Text & Media', $markers['record.type']);
        self::assertStringContainsString('edit%5Btt_content%5D%5B12%5D=edit', $markers['record.backendUrl']);

        self::assertSame('main', $markers['site.identifier']);
        self::assertSame('https://example.com/', $markers['site.base']);

        self::assertSame(ReportFixture::document()['summary'], $markers['context.summary']);
        self::assertSame('Content Element "Hero teaser"', $markers['context.subject']);
        self::assertSame('https://example.com/typo3/record/edit?edit%5Btt_content%5D%5B12%5D=edit', $markers['context.subjectUrl']);
        self::assertSame('English', $markers['context.language']);
        self::assertSame('Live', $markers['context.workspace']);
        self::assertSame('Web › Page', $markers['context.module']);
        self::assertStringContainsString('Page Content', $markers['context.details']);

        self::assertSame('13.4.35', $markers['system.typo3Version']);
        self::assertSame('8.2.33', $markers['system.phpVersion']);
        self::assertSame('Production', $markers['system.applicationContext']);

        self::assertSame('Chrome 128 · macOS · 1440×900 @2x', $markers['browser.summary']);
        self::assertSame(ReportFixture::USER_AGENT, $markers['browser.userAgent']);
        self::assertSame('1440×900', $markers['browser.viewport']);
        self::assertSame('de-AT', $markers['browser.language']);
    }

    #[Test]
    public function fileMarkersDescribeFileFolderAndStorage(): void
    {
        $markers = $this->createMarkers($this->reportWithDocument(ReportFixture::fileDocument()));

        self::assertSame('21', $markers['file.uid']);
        self::assertSame('logo.png', $markers['file.name']);
        self::assertSame('1:/user_upload/logo.png', $markers['file.identifier']);
        self::assertSame('image/png', $markers['file.mimeType']);
        self::assertSame('https://example.com/typo3/module/file/list?id=1:/user_upload/', $markers['file.backendUrl']);
        self::assertSame('1:/user_upload/', $markers['folder.identifier']);
        self::assertSame('user_upload', $markers['folder.name']);
        self::assertSame('fileadmin', $markers['storage.name']);
        self::assertSame('File "logo.png"', $markers['context.subject']);
        self::assertSame('File › Filelist', $markers['context.module']);
        self::assertSame('', $markers['page.title']);
        self::assertSame('', $markers['record.uid']);

        $recordMarkers = $this->createMarkers(ReportFixture::report());
        self::assertSame('', $recordMarkers['file.name']);
        self::assertSame('', $recordMarkers['folder.identifier']);
        self::assertSame('', $recordMarkers['storage.name']);
    }

    #[Test]
    public function reporterNameFallsBackToWhatIsShared(): void
    {
        $document = ReportFixture::document();
        $document['reporter'] = ['uid' => 3];
        $report = $this->reportWithDocument($document);
        self::assertSame('Backend user #3', $this->createMarkers($report)['reporter.name']);

        unset($document['reporter']);
        $report = $this->reportWithDocument($document);
        $markers = $this->createMarkers($report);
        self::assertSame('(not shared)', $markers['reporter.name']);
        self::assertSame('', $markers['reporter.email']);
    }

    #[Test]
    public function missingSectionsProduceEmptyValuesInsteadOfMissingKeys(): void
    {
        $document = ReportFixture::document();
        unset($document['context']['page'], $document['context']['record'], $document['browser']);
        $markers = $this->createMarkers($this->reportWithDocument($document));

        self::assertSame('', $markers['page.title']);
        self::assertSame('', $markers['record.uid']);
        self::assertSame('', $markers['browser.summary']);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function reportWithDocument(array $document): \Priebera\ContextReporter\Domain\Report
    {
        $report = ReportFixture::report();
        return new \Priebera\ContextReporter\Domain\Report(
            $report->identifier,
            $report->createdAt,
            $report->reporterUid,
            $report->source,
            $report->title,
            $report->description,
            \Priebera\ContextReporter\Domain\ContextDocument::fromArray($document),
            $report->screenshot,
        );
    }

    /**
     * @return array<string, string>
     */
    private function createMarkers(\Priebera\ContextReporter\Domain\Report $report): array
    {
        $payload = (new ReportPayloadFactory(new ExtensionInfo('1.0.0')))->create($report, 'https://example.com/typo3/report');
        return (new ReportMarkers(new ContextDetailsFormatter()))->fromPayload($payload);
    }
}
