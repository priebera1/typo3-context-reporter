<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery;

/**
 * @internal
 */
final readonly class DeliveryOutcome
{
    private function __construct(
        public bool $successful,
        public string $message,
        public ?int $responseCode,
        public string $externalReference,
        public string $externalUrl,
    ) {}

    public static function success(string $message = '', ?int $responseCode = null, string $externalReference = '', string $externalUrl = ''): self
    {
        return new self(true, $message, $responseCode, $externalReference, $externalUrl);
    }

    public static function failure(string $message, ?int $responseCode = null): self
    {
        return new self(false, $message, $responseCode, '', '');
    }
}
