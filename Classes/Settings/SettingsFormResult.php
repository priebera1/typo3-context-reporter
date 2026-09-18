<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Settings;

/**
 * @internal
 */
final readonly class SettingsFormResult
{
    /**
     * @param array<string, mixed> $values Normalized values of the section, ready to be stored
     * @param array<string, string> $errors Field name => label key of the problem
     */
    public function __construct(
        public array $values,
        public array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
