<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Settings;

/**
 * Describes the Context Reporter Hub connection without its secrets: the
 * webhook URL is reduced to scheme and host, the secret to its state.
 *
 * @internal
 */
final readonly class HubStatus
{
    /**
     * @param string $endpointTarget Scheme, host and port of a stored webhook URL
     * @param list<StatusMessage> $messages
     */
    public function __construct(
        public bool $enabled,
        public SecretState $endpoint,
        public string $endpointVariable,
        public string $endpointTarget,
        public SecretState $signature,
        public string $signatureVariable,
        public bool $includeScreenshot,
        public array $messages,
    ) {}

    public function isReady(): bool
    {
        if (!$this->enabled || !$this->canSendTest()) {
            return false;
        }
        foreach ($this->messages as $message) {
            if ($message->isBlocking()) {
                return false;
            }
        }
        return true;
    }

    /**
     * The Hub only accepts signed deliveries.
     */
    public function canSendTest(): bool
    {
        return $this->endpoint->isUsable() && $this->signature->isUsable();
    }
}
