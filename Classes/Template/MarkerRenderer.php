<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Template;

/**
 * Deliberately tiny marker system for email subjects and bodies:
 * "{group.name}" is replaced by a value from a flat map. There are no
 * conditions, loops or filters. Values are inserted in a single pass, so
 * marker-like text inside user input is never expanded. Markers that are not
 * part of the map stay untouched, so typos remain visible in the output.
 *
 * @internal
 */
final class MarkerRenderer
{
    private const MARKER = '/\{([a-z][a-zA-Z0-9]*\.[a-zA-Z][a-zA-Z0-9]*)\}/';

    /**
     * @param array<string, string> $markers
     */
    public function render(string $template, array $markers, bool $singleLine = false): string
    {
        $result = (string)preg_replace_callback(
            self::MARKER,
            static fn(array $match): string => array_key_exists($match[1], $markers) ? $markers[$match[1]] : $match[0],
            $template,
        );

        if ($singleLine) {
            $result = (string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $result);
            return trim((string)preg_replace('/ {2,}/', ' ', $result));
        }

        $result = str_replace(["\r\n", "\r"], "\n", $result);
        // Keep line breaks and tabs, drop all other control characters.
        return (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $result);
    }
}
