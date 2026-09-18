<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Retention;

/**
 * @internal
 */
final readonly class CleanupResult
{
    /**
     * @param int $reports Removed reports, including their screenshots and delivery history
     */
    public function __construct(
        public int $reports,
        public int $orphanedAttachments,
        public int $orphanedDeliveries,
    ) {}
}
