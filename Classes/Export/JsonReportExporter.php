<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Export;

/**
 * @internal
 */
final class JsonReportExporter
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * @param array<string, mixed> $payload
     */
    public function export(array $payload, bool $pretty = true): string
    {
        return json_encode($payload, $pretty ? self::FLAGS | JSON_PRETTY_PRINT : self::FLAGS) . ($pretty ? "\n" : '');
    }
}
