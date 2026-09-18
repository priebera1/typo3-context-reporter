<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Configuration;

/**
 * Secrets in here must never be copied into report data, delivery audit
 * records, log messages or backend output.
 *
 * @internal
 */
final readonly class WebhookSettings
{
    /**
     * Shorter endpoint parts (e.g. "/hook") are too common to be redacted.
     */
    private const MIN_DERIVED_SECRET_LENGTH = 6;

    public function __construct(
        public bool $enabled,
        public string $url,
        public string $secret,
        public string $authHeaderName,
        public string $authHeaderValue,
        public bool $includeScreenshot,
        public int $timeoutSeconds,
        public bool $allowInsecureHttp,
    ) {}

    public function isUsable(): bool
    {
        return $this->enabled && $this->url !== '';
    }

    /**
     * Scheme and host only: webhook paths and query strings often embed secrets
     * (n8n and Make use unguessable path tokens).
     */
    public function getDisplayTarget(): string
    {
        $parts = parse_url($this->url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Values that must never appear in stored or displayed messages and
     * references: the secrets, the credential of an authentication value
     * ("Bearer <credential>"), the endpoint and the parts of it that often
     * carry tokens (a longer path, the query and its values).
     *
     * @return list<string>
     */
    public function getSecrets(): array
    {
        $secrets = [$this->secret, $this->authHeaderValue, $this->url];
        if (preg_match('/^[A-Za-z][A-Za-z0-9._~+-]*\s+(\S.*)$/D', $this->authHeaderValue, $matches)) {
            $secrets[] = $matches[1];
        }
        $parts = parse_url($this->url);
        $path = is_array($parts) ? (string)($parts['path'] ?? '') : '';
        $query = is_array($parts) ? (string)($parts['query'] ?? '') : '';
        if (strlen($path) >= self::MIN_DERIVED_SECRET_LENGTH) {
            $secrets[] = $path;
        }
        $secrets[] = $query;
        parse_str($query, $parameters);
        array_walk_recursive($parameters, static function (mixed $value) use (&$secrets): void {
            if (is_string($value) && strlen($value) >= self::MIN_DERIVED_SECRET_LENGTH) {
                $secrets[] = $value;
            }
        });
        return array_values(array_unique(array_filter($secrets, static fn(string $value): bool => $value !== '')));
    }
}
