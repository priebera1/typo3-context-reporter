<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery;

/**
 * @internal
 */
final class DeliveryNotPossibleException extends \RuntimeException
{
    public const DESTINATION_UNAVAILABLE = 'destinationUnavailable';
    public const NOTHING_TO_RETRY = 'nothingToRetry';
    public const SCHEMA_OUTDATED = 'schemaOutdated';
    /**
     * Another request is delivering to this destination, or has just handled the failed attempt
     */
    public const IN_PROGRESS = 'inProgress';

    public static function create(string $reason): self
    {
        return new self($reason, 1757930401);
    }

    public function getReason(): string
    {
        return $this->getMessage();
    }
}
