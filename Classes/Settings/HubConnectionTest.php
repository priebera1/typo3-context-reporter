<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Settings;

/**
 * The outcome of the last Hub connection test.
 *
 * @internal
 */
final readonly class HubConnectionTest
{
    /**
     * @param string $message Safe to display: secrets were removed when the test was made
     * @param bool $current Made with the webhook URL and secret that are configured now
     */
    public function __construct(
        public \DateTimeImmutable $testedAt,
        public bool $successful,
        public ?int $responseCode,
        public string $message,
        public bool $current,
    ) {}

    /**
     * What a failed test means for the administrator, as the last part of the
     * label key "settings.test.hub.failed.<reason>". The Hub answers 401 for
     * every signature problem, 404 for an unknown webhook URL and 410 for a
     * disconnected site; a 2xx answer without the Hub's confirmation did not
     * come from the Hub.
     */
    public static function failureReason(?int $responseCode): string
    {
        return match (true) {
            $responseCode === null => 'unreachable',
            $responseCode >= 200 && $responseCode < 300 => 'notConfirmed',
            $responseCode === 401 => 'signature',
            $responseCode === 404 => 'unknownUrl',
            $responseCode === 410 => 'disconnected',
            $responseCode === 429 => 'rateLimited',
            $responseCode >= 300 && $responseCode < 400 => 'redirect',
            $responseCode >= 500 => 'unavailable',
            default => 'rejected',
        };
    }
}
