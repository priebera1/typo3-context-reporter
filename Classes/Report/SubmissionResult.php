<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Report;

use Priebera\ContextReporter\Domain\DeliveryAttempt;
use Priebera\ContextReporter\Domain\DeliveryState;
use Priebera\ContextReporter\Domain\DeliveryStatus;
use Priebera\ContextReporter\Domain\Report;

/**
 * @internal
 */
final readonly class SubmissionResult
{
    /**
     * @param list<DeliveryAttempt> $attempts
     * @param bool $replayed The draft was submitted before: the stored report, nothing stored or delivered again
     */
    public function __construct(
        public Report $report,
        public array $attempts,
        public bool $replayed = false,
    ) {}

    public function getDeliveryState(): DeliveryState
    {
        $statuses = [];
        foreach ($this->attempts as $attempt) {
            $statuses[$attempt->destination] = $attempt->status;
        }
        return DeliveryState::fromLatestStatuses(array_filter(
            $statuses,
            static fn(DeliveryStatus $status, string $destination): bool => $destination !== DeliveryAttempt::DESTINATION_DOWNLOAD,
            ARRAY_FILTER_USE_BOTH,
        ));
    }
}
