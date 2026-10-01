<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Visibility;

/**
 * The stored visibility settings of one record and the reasons they give at
 * a point in time.
 *
 * @internal
 */
final readonly class RowVisibility
{
    /**
     * @param int $startTime Unix timestamp, 0 when not set
     * @param int $endTime Unix timestamp, 0 when not set
     * @param list<int> $frontendGroups Frontend user group UIDs, -1 (hide at login) and -2 (show at any login)
     * @param list<string> $reasons VisibilityEvaluator::HIDDEN, SCHEDULED, EXPIRED, ACCESS_RESTRICTED
     */
    public function __construct(
        public bool $hidden,
        public int $startTime,
        public int $endTime,
        public array $frontendGroups,
        public array $reasons,
    ) {}
}
