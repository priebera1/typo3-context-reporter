<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Retention;

/**
 * What the report history currently stores in the database.
 *
 * @internal
 */
final readonly class StorageStatistics
{
    public int $resolvedReports;

    public function __construct(
        public int $reports,
        public int $openReports,
        public int $screenshots,
        public int $screenshotBytes,
        public int $deliveries,
        public ?\DateTimeImmutable $oldestReport,
        public int $orphanedAttachments,
        public int $orphanedDeliveries,
    ) {
        $this->resolvedReports = max(0, $reports - $openReports);
    }
}
