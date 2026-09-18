<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Report;

/**
 * What the report dialog sends when the reporter submits.
 *
 * @internal
 */
final readonly class ReportSubmission
{
    /**
     * @param bool $deliver false stores the report for download only
     */
    public function __construct(
        public string $draftToken,
        public string $title,
        public string $description,
        public ?string $screenshotContent,
        public bool $deliver,
    ) {}
}
