<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Domain;

/**
 * One entry of the delivery audit trail. Never contains secrets.
 */
final readonly class DeliveryAttempt
{
    public const DESTINATION_DOWNLOAD = 'download';

    public function __construct(
        public int $reportUid,
        public string $destination,
        public int $attempt,
        public DeliveryStatus $status,
        public \DateTimeImmutable $createdAt,
        public string $target = '',
        public int $triggeredBy = 0,
        public ?int $responseCode = null,
        public string $message = '',
        public string $externalReference = '',
        public string $externalUrl = '',
        public int $durationMs = 0,
        public int $uid = 0,
    ) {}

    public function isDownload(): bool
    {
        return $this->destination === self::DESTINATION_DOWNLOAD;
    }
}
