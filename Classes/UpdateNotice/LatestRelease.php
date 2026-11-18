<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\UpdateNotice;

/**
 * The latest stable Context Reporter release, as the release endpoint of
 * Context Reporter Hub reports it (`context-reporter.release.v1`). Every field
 * comes from the network and is validated here: a version that is not a
 * stable MAJOR.MINOR.PATCH rejects the whole answer, a link that is not an
 * https URL on a known release or package host is replaced by the TER page.
 *
 * @internal
 */
final readonly class LatestRelease
{
    public const FALLBACK_RELEASE_URL = 'https://extensions.typo3.org/extension/context_reporter';

    private const ALLOWED_LINK_HOSTS = [
        'github.com',
        'extensions.typo3.org',
        'packagist.org',
        'docs.typo3.org',
        'typo3.priebera.sk',
        'hub.priebera.sk',
    ];
    private const MAX_URL_LENGTH = 500;

    private function __construct(
        public SemanticVersion $version,
        public string $releasedAt,
        public string $releaseUrl,
    ) {}

    /**
     * Reads the `release` object of the endpoint, or a cached copy of it.
     */
    public static function fromArray(mixed $release): ?self
    {
        if (!is_array($release)) {
            return null;
        }
        $version = is_string($release['version'] ?? null) ? SemanticVersion::tryParse($release['version']) : null;
        if ($version === null) {
            return null;
        }
        return new self(
            $version,
            self::sanitizeDate($release['releasedAt'] ?? null),
            self::sanitizeLink($release['releaseUrl'] ?? null),
        );
    }

    /**
     * @return array{version: string, releasedAt: string, releaseUrl: string}
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version->toString(),
            'releasedAt' => $this->releasedAt,
            'releaseUrl' => $this->releaseUrl,
        ];
    }

    public static function isAllowedLink(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || preg_match('/[\x00-\x20\x7f"<>\\\\`]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        return strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['port'])
            && in_array(strtolower((string)($parts['host'] ?? '')), self::ALLOWED_LINK_HOSTS, true);
    }

    private static function sanitizeLink(mixed $value): string
    {
        $url = is_string($value) ? trim($value) : '';
        return self::isAllowedLink($url) ? $url : self::FALLBACK_RELEASE_URL;
    }

    private static function sanitizeDate(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }
}
