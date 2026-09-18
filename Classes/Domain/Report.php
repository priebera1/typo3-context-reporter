<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Domain;

/**
 * A support report. The reported data is immutable once created; only the
 * delivery audit trail and the review state change afterwards.
 */
final readonly class Report
{
    public function __construct(
        public string $identifier,
        public \DateTimeImmutable $createdAt,
        public int $reporterUid,
        public ReportSource $source,
        public string $title,
        public string $description,
        public ContextDocument $document,
        public ?AttachmentMetadata $screenshot = null,
        public int $uid = 0,
        public DeliveryState $deliveryState = DeliveryState::Local,
        public ReviewState $reviewState = ReviewState::Open,
        public ?\DateTimeImmutable $resolvedAt = null,
        public int $resolvedBy = 0,
    ) {}

    public function withPersistenceIdentity(int $uid): self
    {
        return new self(
            $this->identifier,
            $this->createdAt,
            $this->reporterUid,
            $this->source,
            $this->title,
            $this->description,
            $this->document,
            $this->screenshot,
            $uid,
            $this->deliveryState,
            $this->reviewState,
            $this->resolvedAt,
            $this->resolvedBy,
        );
    }

    public function withDeliveryState(DeliveryState $deliveryState): self
    {
        return new self(
            $this->identifier,
            $this->createdAt,
            $this->reporterUid,
            $this->source,
            $this->title,
            $this->description,
            $this->document,
            $this->screenshot,
            $this->uid,
            $deliveryState,
            $this->reviewState,
            $this->resolvedAt,
            $this->resolvedBy,
        );
    }
}
