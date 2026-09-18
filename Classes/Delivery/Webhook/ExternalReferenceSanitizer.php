<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery\Webhook;

use Priebera\ContextReporter\Delivery\SafeErrorMessage;

/**
 * Keeps secrets out of the ticket reference and link that a webhook
 * receiver returns. They are stored in the delivery history and shown to
 * the reporter, who is usually not the administrator who configured the
 * webhook, so a receiver that echoes the request must not leak it.
 *
 * A reference that is a URL or contains a secret is dropped. A link keeps
 * working without credential-like query parameters; it is dropped when it
 * contains user information or a secret.
 *
 * @internal
 */
final class ExternalReferenceSanitizer
{
    private const URL = '~[a-z][a-z0-9+.\-]*://~i';
    private const SENSITIVE_PARAMETER = '/(?:^|[^a-z])(?:access[_-]?key|api[_-]?key|auth(?:orization)?|client[_-]?secret|credentials?|jwt|pass(?:code|word|wd)?|private[_-]?key|pwd|secret|session(?:[_-]?id)?|sig(?:nature)?|token)(?:$|[^a-z])/i';

    /**
     * @param list<string> $secrets
     */
    public static function reference(string $reference, array $secrets): string
    {
        if ($reference === '' || preg_match(self::URL, $reference) || self::containsSecret($reference, $secrets)) {
            return '';
        }
        return $reference;
    }

    /**
     * @param list<string> $secrets
     */
    public static function url(string $url, array $secrets): string
    {
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || isset($parts['user'])
            || isset($parts['pass'])
            || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || (string)($parts['host'] ?? '') === ''
        ) {
            return '';
        }
        $url = self::removeSensitiveParameters($url);
        return self::containsSecret($url, $secrets) ? '' : $url;
    }

    private static function removeSensitiveParameters(string $url): string
    {
        $fragment = '';
        $position = strpos($url, '#');
        if ($position !== false) {
            $fragment = substr($url, $position);
            $url = substr($url, 0, $position);
            if (preg_match(self::SENSITIVE_PARAMETER, rawurldecode($fragment))) {
                $fragment = '';
            }
        }
        $query = '';
        $position = strpos($url, '?');
        if ($position !== false) {
            $query = substr($url, $position + 1);
            $url = substr($url, 0, $position);
        }
        $kept = [];
        foreach (explode('&', $query) as $pair) {
            $name = rawurldecode(str_replace('+', ' ', explode('=', $pair, 2)[0]));
            if ($pair !== '' && !preg_match(self::SENSITIVE_PARAMETER, $name)) {
                $kept[] = $pair;
            }
        }
        return $url . ($kept !== [] ? '?' . implode('&', $kept) : '') . $fragment;
    }

    /**
     * @param list<string> $secrets
     */
    private static function containsSecret(string $value, array $secrets): bool
    {
        return SafeErrorMessage::containsSecret($value, $secrets)
            || SafeErrorMessage::containsSecret(rawurldecode($value), $secrets);
    }
}
