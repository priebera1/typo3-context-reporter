<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Settings;

use Priebera\ContextReporter\Configuration\EnvironmentReference;

/**
 * How a secret-like setting is configured. The backend shows this state,
 * never the value.
 *
 * @internal
 */
enum SecretState: string
{
    case NotConfigured = 'notConfigured';
    case Configured = 'configured';
    case Environment = 'environment';
    case EnvironmentUnavailable = 'environmentUnavailable';
    case Invalid = 'invalid';

    /**
     * @param string $rawValue The configured value, possibly an %env(NAME)% reference
     * @param string $effectiveValue The resolved and validated value, empty when unusable
     */
    public static function of(string $rawValue, string $effectiveValue): self
    {
        $rawValue = trim($rawValue);
        if ($rawValue === '') {
            return self::NotConfigured;
        }
        if (EnvironmentReference::isReference($rawValue)) {
            return $effectiveValue !== '' ? self::Environment : self::EnvironmentUnavailable;
        }
        return $effectiveValue !== '' ? self::Configured : self::Invalid;
    }

    public function isUsable(): bool
    {
        return $this === self::Configured || $this === self::Environment;
    }
}
