<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Export;

/**
 * A short plain text version of a report for the clipboard, e.g. to paste
 * into a chat or a ticket: title, key facts, link and description.
 *
 * @internal
 */
final readonly class ReportSummaryExporter
{
    private const SEPARATOR = ' · ';

    public function __construct(
        private ContextDetailsFormatter $detailsFormatter,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function export(array $payload): string
    {
        $markers = (new ReportMarkers($this->detailsFormatter))->fromPayload($payload);
        $reporter = is_array($payload['reporter'] ?? null) && $payload['reporter'] !== [] ? $markers['reporter.name'] : '';

        $lines = [$this->singleLine($markers['report.title'])];
        $facts = [
            'Report' => implode(self::SEPARATOR, array_filter(
                [$markers['report.id'], $markers['report.createdAt'], $reporter, $markers['report.source']],
                static fn(string $value): bool => $value !== '',
            )),
            'Context' => $markers['context.summary'],
            'Project' => $markers['project.name'] . ($markers['project.environment'] !== '' ? ' (' . $markers['project.environment'] . ')' : ''),
            'Link' => $markers['report.url'],
        ];
        foreach ($facts as $label => $value) {
            $value = $this->singleLine($value);
            if ($value !== '') {
                $lines[] = $label . ': ' . $value;
            }
        }

        $description = trim(str_replace(["\r\n", "\r"], "\n", $markers['report.description']));
        if ($description !== '') {
            $lines[] = '';
            $lines[] = $description;
        }
        return implode("\n", $lines) . "\n";
    }

    private function singleLine(string $value): string
    {
        return trim((string)preg_replace('/\s*\R\s*/', ' ', $value));
    }
}
