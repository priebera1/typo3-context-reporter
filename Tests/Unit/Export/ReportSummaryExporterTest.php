<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\ExtensionInfo;
use Priebera\ContextReporter\Domain\Report;
use Priebera\ContextReporter\Export\ContextDetailsFormatter;
use Priebera\ContextReporter\Export\ReportPayloadFactory;
use Priebera\ContextReporter\Export\ReportSummaryExporter;
use Priebera\ContextReporter\Tests\Unit\Fixtures\ReportFixture;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ReportSummaryExporterTest extends UnitTestCase
{
    #[Test]
    public function summaryContainsTheKeyFactsAndTheDescription(): void
    {
        $summary = $this->export(ReportFixture::report(), 'https://example.com/typo3/module/system/context-reports/show?report=CR-7K3Q-9XMA-2B4F');

        self::assertSame(
            "Cannot select an image\n"
            . "Report: CR-7K3Q-9XMA-2B4F · 2026-09-16 10:20 UTC · Erika Editor · Record editing form\n"
            . 'Context: Content Element "Hero teaser" [tt_content:12] on page "Home" [1] · site main · language English · workspace Live' . "\n"
            . "Project: Agency Portal (Production)\n"
            . "Link: https://example.com/typo3/module/system/context-reports/show?report=CR-7K3Q-9XMA-2B4F\n"
            . "\n"
            . "The image selector shows no files.\n"
            . "It worked yesterday.\n",
            $summary,
        );
    }

    #[Test]
    public function missingValuesAreLeftOut(): void
    {
        $document = ReportFixture::document();
        unset($document['reporter'], $document['project']['environment']);

        $summary = $this->export(ReportFixture::report(description: "  \n", document: $document), '');

        self::assertSame(
            "Cannot select an image\n"
            . "Report: CR-7K3Q-9XMA-2B4F · 2026-09-16 10:20 UTC · Record editing form\n"
            . 'Context: Content Element "Hero teaser" [tt_content:12] on page "Home" [1] · site main · language English · workspace Live' . "\n"
            . "Project: Agency Portal\n",
            $summary,
        );
    }

    #[Test]
    public function payloadForTheClipboardHasNoScreenshotData(): void
    {
        $payload = (new ReportPayloadFactory(new ExtensionInfo('1.0.0')))->create(ReportFixture::report(), 'https://example.com/r');

        self::assertSame('context-reporter.report.v1', $payload['schema']);
        self::assertArrayNotHasKey('contentBase64', $payload['attachments'][0]);
        self::assertSame('CR-7K3Q-9XMA-2B4F-screenshot.png', $payload['attachments'][0]['filename']);
    }

    private function export(Report $report, string $reportUrl): string
    {
        $payload = (new ReportPayloadFactory(new ExtensionInfo('1.0.0')))->create($report, $reportUrl);
        return (new ReportSummaryExporter(new ContextDetailsFormatter()))->export($payload);
    }
}
