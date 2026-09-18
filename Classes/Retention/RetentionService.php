<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Retention;

use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Priebera\ContextReporter\Domain\Repository\ReportRepository;

/**
 * Applies the retention of the report history. Reports are only removed when
 * an administrator runs the cleanup (Context Reports settings or the console
 * command); with the default retention "keep forever" no report is removed.
 *
 * A cleanup also removes attachments and delivery attempts whose report no
 * longer exists.
 *
 * @internal
 */
final readonly class RetentionService
{
    private const SECONDS_PER_DAY = 86400;

    public function __construct(
        private ReportRepository $reports,
        private ExtensionSettingsProvider $settingsProvider,
    ) {}

    public function getConfiguredDays(): int
    {
        return $this->settingsProvider->get()->retentionDays;
    }

    public function getStatistics(): StorageStatistics
    {
        $statistics = $this->reports->getStorageStatistics();
        $orphans = $this->reports->countOrphans();
        $oldest = $this->reports->findOldestCreationTime();
        return new StorageStatistics(
            reports: $statistics['reports'],
            openReports: $statistics['openReports'],
            screenshots: $statistics['screenshots'],
            screenshotBytes: $statistics['screenshotBytes'],
            deliveries: $statistics['deliveries'],
            oldestReport: $oldest !== null ? $this->createDate($oldest) : null,
            orphanedAttachments: $orphans['attachments'],
            orphanedDeliveries: $orphans['deliveries'],
        );
    }

    /**
     * @param int|null $days Retention in days, the configured retention when null; 0 keeps reports forever
     */
    public function preview(?int $days = null, ?int $now = null): CleanupPreview
    {
        $days = $this->assertDays($days ?? $this->getConfiguredDays());
        $cutoff = $this->getCutoff($days, $now ?? time());
        $statistics = $cutoff !== null
            ? $this->reports->getStorageStatistics($cutoff)
            : ['reports' => 0, 'openReports' => 0, 'screenshots' => 0, 'screenshotBytes' => 0, 'deliveries' => 0];
        $orphans = $this->reports->countOrphans();

        return new CleanupPreview(
            days: $days,
            cutoff: $cutoff !== null ? $this->createDate($cutoff) : null,
            reports: $statistics['reports'],
            openReports: $statistics['openReports'],
            screenshots: $statistics['screenshots'],
            screenshotBytes: $statistics['screenshotBytes'],
            deliveries: $statistics['deliveries'],
            orphanedAttachments: $orphans['attachments'],
            orphanedDeliveries: $orphans['deliveries'],
        );
    }

    /**
     * Removes reports older than the given number of days together with their
     * screenshots and delivery history, and orphaned rows.
     *
     * @param int $days 0 removes orphaned rows only
     */
    public function cleanup(int $days, ?int $now = null): CleanupResult
    {
        $cutoff = $this->getCutoff($this->assertDays($days), $now ?? time());
        $removedReports = $cutoff !== null ? $this->reports->deleteCreatedBefore($cutoff) : 0;
        $orphans = $this->reports->deleteOrphans();
        return new CleanupResult($removedReports, $orphans['attachments'], $orphans['deliveries']);
    }

    private function getCutoff(int $days, int $now): ?int
    {
        return $days > 0 ? $now - $days * self::SECONDS_PER_DAY : null;
    }

    private function assertDays(int $days): int
    {
        if ($days < 0) {
            throw new \InvalidArgumentException('The retention must not be negative.', 1758200001);
        }
        return $days;
    }

    private function createDate(int $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
}
