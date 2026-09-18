<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Configuration;

/**
 * A setting value of the form %env(NAME)% (the syntax TYPO3 uses in YAML
 * files) that is read from an environment variable when it is used. The
 * resolved value is never shown in the backend.
 *
 * @internal
 */
final class EnvironmentReference
{
    private const PATTERN = '/^%env\(([A-Za-z_][A-Za-z0-9_]*)\)%$/D';

    public static function isReference(string $value): bool
    {
        return self::getVariableName($value) !== null;
    }

    public static function getVariableName(string $value): ?string
    {
        return preg_match(self::PATTERN, $value, $matches) === 1 ? $matches[1] : null;
    }
}
