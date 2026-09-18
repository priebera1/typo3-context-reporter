<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Settings;

/**
 * Describes the webhook configuration without its secrets: the endpoint is
 * reduced to scheme and host, secrets to their state.
 *
 * @internal
 */
final readonly class WebhookStatus
{
    /**
     * @param string $endpointTarget Scheme, host and port of a stored endpoint
     * @param bool $endpointHasPath The stored endpoint has a (hidden) path or query
     * @param string $authHeaderName The header that is sent, empty when none is sent
     * @param list<StatusMessage> $messages
     */
    public function __construct(
        public bool $enabled,
        public SecretState $endpoint,
        public string $endpointVariable,
        public string $endpointTarget,
        public bool $endpointHasPath,
        public SecretState $signature,
        public string $signatureVariable,
        public string $authHeaderName,
        public SecretState $authHeaderValue,
        public string $authHeaderValueVariable,
        public int $timeout,
        public bool $includeScreenshot,
        public bool $allowInsecureHttp,
        public array $messages,
    ) {}

    public function isReady(): bool
    {
        if (!$this->enabled || !$this->endpoint->isUsable()) {
            return false;
        }
        foreach ($this->messages as $message) {
            if ($message->isBlocking()) {
                return false;
            }
        }
        return true;
    }

    public function canSendTest(): bool
    {
        return $this->endpoint->isUsable();
    }
}
