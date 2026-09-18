<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Domain;

use Priebera\ContextReporter\Report\Screenshot;

/**
 * Describes a stored attachment. The binary content is stored and loaded
 * separately.
 */
final readonly class AttachmentMetadata
{
    public const TYPE_SCREENSHOT = 'screenshot';

    public function __construct(
        public string $type,
        public string $filename,
        public string $mediaType,
        public int $size,
        public int $width,
        public int $height,
        public string $sha256,
    ) {}

    public static function fromScreenshot(Screenshot $screenshot, string $reportIdentifier): self
    {
        return new self(
            type: self::TYPE_SCREENSHOT,
            filename: $screenshot->getFilename($reportIdentifier),
            mediaType: $screenshot->mediaType,
            size: $screenshot->size,
            width: $screenshot->width,
            height: $screenshot->height,
            sha256: $screenshot->sha256,
        );
    }

    /**
     * @return array{type: string, filename: string, mediaType: string, size: int, width: int, height: int, sha256: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'filename' => $this->filename,
            'mediaType' => $this->mediaType,
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
            'sha256' => $this->sha256,
        ];
    }
}
