<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Hub;

/**
 * Links into Context Reporter Hub. The Hub is write-only for TYPO3: reports are
 * sent to it, nothing is read back. Links come from the Hub's acknowledgement
 * of a report and are only kept on the trusted Hub address ("Hub address for
 * links" or the address of the webhook URL), inside the Hub app.
 *
 * @internal
 */
final class HubLink
{
    /**
     * A link into the Hub app: same scheme, host and port as the trusted Hub
     * address, below the path the Hub lives under ("<prefix>/app/…"), without
     * credentials, query or fragment. The address is the "Hub address for
     * links" or the webhook URL (its /in/s_… part is not part of the prefix).
     */
    public static function trusted(mixed $value, string $linkBase): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 500 || preg_match('/[\x00-\x20\x7f"<>\\\\]/', $value) === 1) {
            return '';
        }
        $link = parse_url($value);
        $hub = parse_url($linkBase);
        if (!is_array($link) || !is_array($hub)
            || isset($link['user']) || isset($link['pass']) || isset($link['query']) || isset($link['fragment'])
        ) {
            return '';
        }
        $scheme = strtolower((string)($link['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)
            || $scheme !== strtolower((string)($hub['scheme'] ?? ''))
            || strtolower((string)($link['host'] ?? '')) !== strtolower((string)($hub['host'] ?? ''))
            || self::port($link) !== self::port($hub)
        ) {
            return '';
        }
        $prefix = rtrim((string)preg_replace('~/in/s_[A-Za-z0-9]+$~D', '', (string)($hub['path'] ?? '')), '/');
        $path = (string)($link['path'] ?? '');
        if (!str_starts_with($path, $prefix . '/app/') || str_contains($path, '/../') || str_contains($path, '/./')
            || str_ends_with($path, '/..') || str_ends_with($path, '/.') || stripos($path, '%2e') !== false || str_contains($path, '\\')
        ) {
            return '';
        }
        return $value;
    }

    /**
     * @param array<string, int|string> $parts
     */
    private static function port(array $parts): int
    {
        return (int)($parts['port'] ?? (strtolower((string)($parts['scheme'] ?? '')) === 'http' ? 80 : 443));
    }

    /**
     * The Hub app ("Open Context Reporter Hub") on the trusted Hub address, or
     * an empty string without a usable address.
     */
    public static function app(string $linkBase): string
    {
        $hub = parse_url($linkBase);
        if (!is_array($hub) || !in_array(strtolower((string)($hub['scheme'] ?? '')), ['http', 'https'], true) || ($hub['host'] ?? '') === ''
            || isset($hub['user']) || isset($hub['pass']) || isset($hub['query']) || isset($hub['fragment'])
        ) {
            return '';
        }
        $prefix = rtrim((string)preg_replace('~/in/s_[A-Za-z0-9]+$~D', '', (string)($hub['path'] ?? '')), '/');
        $port = isset($hub['port']) ? ':' . (int)$hub['port'] : '';
        return strtolower((string)($hub['scheme'] ?? '')) . '://' . $hub['host'] . $port . $prefix . '/app';
    }
}
