<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Export;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\ExtensionInfo;
use Priebera\ContextReporter\Export\ContextDetailsFormatter;
use Priebera\ContextReporter\Export\MarkdownReportExporter;
use Priebera\ContextReporter\Export\ReportMarkers;
use Priebera\ContextReporter\Export\ReportPayloadFactory;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use Priebera\ContextReporter\Tests\Unit\Fixtures\ReportFixture;

/**
 * The findings in the exports (Markdown, email marker): all of them, in
 * English, whatever the backend language of the person who exports.
 */
final class FindingsExportTest extends AbstractContextReporterTestCase
{
    #[Test]
    public function markdownListsAllFindingsInEnglishBeforeTheContext(): void
    {
        // Erika works in German
        $this->loginBackendUser(self::EDITOR);

        $markdown = $this->get(MarkdownReportExporter::class)->export($this->payload(ReportFixture::documentWithDiagnostics()));

        $findings = substr($markdown, (int)strpos($markdown, "## Findings\n"), (int)strpos($markdown, "## Context\n") - (int)strpos($markdown, "## Findings\n"));
        self::assertStringContainsString("### Website address\n\n- The website address is in \"Français\", which is disabled in the site configuration\n", $findings);
        self::assertStringContainsString("### Placement\n\n- Column 3 is not a column of the backend layout of the page (columns: Main \\[0\\], Sidebar \\[5\\]).", $findings);
        // All seven permission findings, more than the dialog shows
        self::assertSame(7, substr_count(substr($findings, (int)strpos($findings, '### Permissions')), "\n- "));
        self::assertStringContainsString("\n_These are settings and permissions stored in TYPO3, not a check of the website", $findings);
        self::assertStringNotContainsString('Webadresse', $markdown);
    }

    #[Test]
    public function reportsWithoutFindingsHaveNoFindingsSection(): void
    {
        $this->loginBackendUser(self::ADMIN);

        // A report of 0.1.x
        $markdown = $this->get(MarkdownReportExporter::class)->export($this->payload(ReportFixture::document()));

        self::assertStringNotContainsString('## Findings', $markdown);
        self::assertStringContainsString("\n## Context\n", $markdown);
    }

    #[Test]
    public function findingsMarkerIsPlainTextForEmails(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $markers = (new ReportMarkers($this->get(ContextDetailsFormatter::class)))->fromPayload($this->payload(ReportFixture::documentWithDiagnostics()));

        self::assertStringStartsWith(
            "Website address\n  - The website address is in \"Français\", which is disabled in the site configuration\n",
            $markers['context.findings'],
        );
        self::assertStringContainsString("\n\nPermissions\n  - \"Page Content\" is not among the tables you may modify\n", $markers['context.findings']);
        self::assertStringEndsWith("Permission facts are not TYPO3's decision whether something can be edited.", $markers['context.findings']);
        self::assertSame('No findings.', (new ReportMarkers($this->get(ContextDetailsFormatter::class)))->fromPayload($this->payload(ReportFixture::document()))['context.findings']);
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function payload(array $document): array
    {
        return (new ReportPayloadFactory(new ExtensionInfo('1.0.0')))->create(ReportFixture::report(document: $document));
    }
}
