<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Domain;

/**
 * Aggregated delivery state of a report over all push destinations
 * (email, webhook). Downloads do not influence it.
 */
enum DeliveryState: string
{
    /** Stored in the local report history only */
    case Local = 'local';
    case Delivered = 'delivered';
    case Partial = 'partial';
    case Failed = 'failed';

    /**
     * @param array<string, DeliveryStatus> $latestStatusPerDestination
     */
    public static function fromLatestStatuses(array $latestStatusPerDestination): self
    {
        if ($latestStatusPerDestination === []) {
            return self::Local;
        }
        $failed = count(array_filter(
            $latestStatusPerDestination,
            static fn(DeliveryStatus $status): bool => $status === DeliveryStatus::Failed,
        ));
        return match (true) {
            $failed === 0 => self::Delivered,
            $failed === count($latestStatusPerDestination) => self::Failed,
            default => self::Partial,
        };
    }
}
