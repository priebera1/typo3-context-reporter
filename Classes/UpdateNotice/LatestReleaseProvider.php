<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\UpdateNotice;

use Priebera\ContextReporter\Configuration\SettingsRepository;
use Priebera\ContextReporter\Delivery\Http\BoundedHttpClient;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Registry;

/**
 * Reads the latest stable Context Reporter release from the public release
 * endpoint of Context Reporter Hub (no Hub account or connection needed).
 *
 * The request is a plain GET to a fixed address: no query, body, installation
 * id, host name, user or report data, and the answer is never followed to
 * another address. It is cached in the Registry (so a cache flush does not
 * trigger it) for 12 hours, a failure for one hour; with a total time limit of
 * 2 seconds, an unreachable Hub costs one short wait per hour, not one per
 * page load. Failures are silent: a timeout, an HTTP error or a malformed
 * answer simply means "no update notice".
 *
 * The environment variable CONTEXT_REPORTER_RELEASE_CHECK_URL replaces the
 * address for staging and tests (https, or http on a local development host
 * only), or switches the check off ("off"); nothing configurable in the
 * backend can change it.
 *
 * @internal
 */
final readonly class LatestReleaseProvider
{
    public const ENDPOINT = 'https://hub.priebera.sk/api/extension/v1/releases/latest';
    public const ENDPOINT_ENVIRONMENT_VARIABLE = 'CONTEXT_REPORTER_RELEASE_CHECK_URL';
    public const SCHEMA = 'context-reporter.release.v1';
    public const REGISTRY_KEY = 'latestRelease';
    public const CACHE_TTL = 43200;
    public const FAILURE_CACHE_TTL = 3600;
    public const TIMEOUT_SECONDS = 2.0;
    public const MAX_RESPONSE_BYTES = 16384;

    private const DEVELOPMENT_HOST = '/^(?:localhost|127\.0\.0\.1|\[::1\]|host\.docker\.internal|[a-z0-9-]+|[a-z0-9.-]+\.(?:localhost|test|ddev\.site))$/D';

    public function __construct(
        private BoundedHttpClient $httpClient,
        private Registry $registry,
        private Context $context,
        private LoggerInterface $logger,
    ) {}

    public function getLatestRelease(): ?LatestRelease
    {
        $endpoint = self::resolveEndpoint(getenv(self::ENDPOINT_ENVIRONMENT_VARIABLE));
        if ($endpoint === '') {
            return null;
        }
        $now = $this->now();
        try {
            $cached = $this->registry->get(SettingsRepository::REGISTRY_NAMESPACE, self::REGISTRY_KEY);
        } catch (\Throwable) {
            $cached = null;
        }
        if (is_array($cached) && is_int($cached['checkedAt'] ?? null) && array_key_exists('release', $cached)) {
            $release = LatestRelease::fromArray($cached['release']);
            $age = $now - $cached['checkedAt'];
            // A check "from the future" (clock changed) is not trusted beyond its own period
            if ($age >= 0 && $age < ($release !== null ? self::CACHE_TTL : self::FAILURE_CACHE_TTL)) {
                return $release;
            }
        }

        $release = $this->fetch($endpoint);
        try {
            $this->registry->set(SettingsRepository::REGISTRY_NAMESPACE, self::REGISTRY_KEY, [
                'checkedAt' => $now,
                'release' => $release?->toArray(),
            ]);
        } catch (\Throwable) {
            // An unavailable Registry must not turn a successful check into an error
        }
        return $release;
    }

    /**
     * The fixed endpoint, a valid override from the environment, or '' when
     * the check is switched off.
     */
    public static function resolveEndpoint(mixed $override): string
    {
        if (!is_string($override) || trim($override) === '') {
            return self::ENDPOINT;
        }
        $url = trim($override);
        if (strtolower($url) === 'off') {
            return '';
        }
        $parts = parse_url($url);
        if (strlen($url) > 500 || preg_match('/[\x00-\x20\x7f]/', $url) === 1 || !is_array($parts)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        ) {
            return self::ENDPOINT;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '' || ($scheme !== 'https' && ($scheme !== 'http' || preg_match(self::DEVELOPMENT_HOST, $host) !== 1))) {
            return self::ENDPOINT;
        }
        return $url;
    }

    private function fetch(string $endpoint): ?LatestRelease
    {
        try {
            $exchange = $this->httpClient->request(
                'GET',
                $endpoint,
                ['Accept' => 'application/json'],
                null,
                self::TIMEOUT_SECONDS,
                [],
                self::TIMEOUT_SECONDS,
                self::MAX_RESPONSE_BYTES,
            );
        } catch (\Throwable) {
            return $this->unavailable('transport_error');
        }
        if (!$exchange->hasStatus()) {
            return $this->unavailable('no_answer');
        }
        if ($exchange->status !== 200) {
            return $this->unavailable('http_' . $exchange->status);
        }
        if (!$exchange->isBodyComplete() || $exchange->body === '') {
            return $this->unavailable('invalid_body');
        }
        try {
            $payload = json_decode($exchange->body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->unavailable('invalid_json');
        }
        if (!is_array($payload) || ($payload['schema'] ?? null) !== self::SCHEMA) {
            return $this->unavailable('invalid_response');
        }
        return LatestRelease::fromArray($payload['release'] ?? null) ?? $this->unavailable('invalid_release');
    }

    private function unavailable(string $reason): null
    {
        $this->logger->info('Context Reporter release check unavailable: {reason}', ['reason' => $reason]);
        return null;
    }

    private function now(): int
    {
        try {
            $timestamp = $this->context->getPropertyFromAspect('date', 'timestamp');
            return is_int($timestamp) ? $timestamp : time();
        } catch (\Throwable) {
            return time();
        }
    }
}
