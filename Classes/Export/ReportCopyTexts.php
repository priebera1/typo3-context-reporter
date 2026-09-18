<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Export;

/**
 * The texts behind the copy actions of the report dialog and the report detail.
 *
 * @internal
 */
final readonly class ReportCopyTexts
{
    public function __construct(
        private ReportSummaryExporter $summaryExporter,
        private MarkdownReportExporter $markdownExporter,
        private JsonReportExporter $jsonExporter,
    ) {}

    /**
     * @param array<string, mixed> $payload Report payload without screenshot content
     * @return array{summary: string, markdown: string, json: string, link: string}
     */
    public function create(array $payload, string $reportUrl): array
    {
        return [
            'summary' => $this->summaryExporter->export($payload),
            'markdown' => $this->markdownExporter->export($payload),
            'json' => $this->jsonExporter->export($payload),
            'link' => $reportUrl,
        ];
    }
}
