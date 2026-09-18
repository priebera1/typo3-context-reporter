<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Settings;

/**
 * A finding about a destination configuration, rendered from the label
 * "settings.status.<key>" of the module.
 *
 * @internal
 */
final readonly class StatusMessage
{
    public const DANGER = 'danger';
    public const WARNING = 'warning';
    public const INFO = 'info';

    /**
     * @param list<string> $arguments
     */
    public function __construct(
        public string $severity,
        public string $key,
        public array $arguments = [],
    ) {}

    public function isBlocking(): bool
    {
        return $this->severity === self::DANGER;
    }
}
