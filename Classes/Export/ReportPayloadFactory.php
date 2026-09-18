<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Export;

use Priebera\ContextReporter\Configuration\ExtensionInfo;
use Priebera\ContextReporter\Domain\Report;

/**
 * Builds the machine readable report document (schema "context-reporter.report.v1")
 * that is used for downloads, email attachments and webhook deliveries alike.
 *
 * @internal
 */
final readonly class ReportPayloadFactory
{
    public const SCHEMA = 'context-reporter.report.v1';

    public function __construct(
        private ExtensionInfo $extensionInfo,
    ) {}

    /**
     * @param string $reportUrl Shareable backend link to the report in the report history
     * @param string|null $screenshotContent When given, the screenshot is embedded as base64
     * @return array<string, mixed>
     */
    public function create(Report $report, string $reportUrl = '', ?string $screenshotContent = null): array
    {
        $document = $report->document->toArray();

        $payload = [
            'schema' => self::SCHEMA,
            'id' => $report->identifier,
            'createdAt' => $report->createdAt->format(\DateTimeInterface::ATOM),
            'source' => $report->source->value,
            'title' => $report->title,
            'description' => $report->description,
            'summary' => $document['summary'] ?? '',
            'subject' => $document['subject'] ?? [],
        ];
        foreach (['project', 'reporter'] as $section) {
            if (isset($document[$section])) {
                $payload[$section] = $document[$section];
            }
        }
        $payload['context'] = $document['context'] ?? new \stdClass();
        foreach (['system', 'browser'] as $section) {
            if (isset($document[$section])) {
                $payload[$section] = $document[$section];
            }
        }

        $attachments = [];
        if ($report->screenshot !== null) {
            $attachment = $report->screenshot->toArray();
            if ($screenshotContent !== null) {
                $attachment['contentBase64'] = base64_encode($screenshotContent);
            }
            $attachments[] = $attachment;
        }
        $payload['attachments'] = $attachments;

        if ($reportUrl !== '') {
            $payload['links'] = ['report' => $reportUrl];
        }
        $payload['generator'] = [
            'name' => ExtensionInfo::PRODUCT_NAME,
            'package' => ExtensionInfo::PACKAGE_NAME,
            'version' => $this->extensionInfo->getVersion(),
        ];
        return $payload;
    }
}
