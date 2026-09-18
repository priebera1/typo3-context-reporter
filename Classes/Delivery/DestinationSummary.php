<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery;

/**
 * What the report dialog may tell the reporter about a destination.
 *
 * @internal
 */
final readonly class DestinationSummary
{
    public function __construct(
        public string $identifier,
        public string $target,
    ) {}
}
