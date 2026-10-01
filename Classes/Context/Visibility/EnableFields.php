<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Visibility;

/**
 * The visibility fields a table declares in TCA "ctrl.enablecolumns".
 * An empty string means the table has no such field.
 *
 * @internal
 */
final readonly class EnableFields
{
    public function __construct(
        public string $hidden = '',
        public string $startTime = '',
        public string $endTime = '',
        public string $frontendGroups = '',
    ) {}
}
