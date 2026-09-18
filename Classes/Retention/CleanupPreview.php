<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Retention;

/**
 * What a cleanup would remove.
 *
 * @internal
 */
final readonly class CleanupPreview
{
    /**
     * @param int $days Retention in days; 0 keeps reports forever
     * @param \DateTimeImmutable|null $cutoff Reports created before this time are removed
     */
    public function __construct(
        public int $days,
        public ?\DateTimeImmutable $cutoff,
        public int $reports,
        public int $openReports,
        public int $screenshots,
        public int $screenshotBytes,
        public int $deliveries,
        public int $orphanedAttachments,
        public int $orphanedDeliveries,
    ) {}

    public function hasWork(): bool
    {
        return $this->reports > 0 || $this->orphanedAttachments > 0 || $this->orphanedDeliveries > 0;
    }
}
