<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Settings;

use Priebera\ContextReporter\Configuration\SettingsRepository;
use Priebera\ContextReporter\Configuration\WebhookSettings;
use Priebera\ContextReporter\Delivery\DeliveryOutcome;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Registry;

/**
 * Remembers the last Hub connection test in the TYPO3 registry, so the
 * settings can show whether the Hub accepted the saved webhook URL and
 * secret. The result is bound to them by a keyed fingerprint (neither is
 * stored); after they change, it no longer counts as current.
 *
 * @internal
 */
final readonly class HubConnectionTestRepository
{
    private const REGISTRY_KEY = 'hubConnectionTest';
    private const HMAC_PURPOSE = 'context-reporter-hub-connection';
    private const MAX_MESSAGE_LENGTH = 500;

    public function __construct(
        private Registry $registry,
        private HashService $hashService,
    ) {}

    /**
     * @param DeliveryOutcome $outcome Its message is already free of secrets
     */
    public function record(WebhookSettings $hub, DeliveryOutcome $outcome, ?int $now = null): void
    {
        $this->registry->set(SettingsRepository::REGISTRY_NAMESPACE, self::REGISTRY_KEY, [
            'time' => $now ?? time(),
            'successful' => $outcome->successful,
            'responseCode' => $outcome->responseCode,
            'message' => mb_substr($outcome->message, 0, self::MAX_MESSAGE_LENGTH),
            'fingerprint' => $this->fingerprint($hub),
        ]);
    }

    public function findLatest(WebhookSettings $hub): ?HubConnectionTest
    {
        $data = $this->registry->get(SettingsRepository::REGISTRY_NAMESPACE, self::REGISTRY_KEY);
        if (!is_array($data) || !is_int($data['time'] ?? null) || !is_bool($data['successful'] ?? null)) {
            return null;
        }
        $fingerprint = $data['fingerprint'] ?? null;
        return new HubConnectionTest(
            testedAt: (new \DateTimeImmutable('@' . $data['time']))->setTimezone(new \DateTimeZone(date_default_timezone_get())),
            successful: $data['successful'],
            responseCode: is_int($data['responseCode'] ?? null) ? $data['responseCode'] : null,
            message: is_string($data['message'] ?? null) ? $data['message'] : '',
            current: $hub->url !== '' && is_string($fingerprint) && hash_equals($this->fingerprint($hub), $fingerprint),
        );
    }

    private function fingerprint(WebhookSettings $hub): string
    {
        return $this->hashService->hmac($hub->url . "\n" . $hub->secret, self::HMAC_PURPOSE);
    }
}
