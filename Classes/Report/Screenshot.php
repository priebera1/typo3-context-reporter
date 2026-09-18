<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Report;

/**
 * A validated, already flattened screenshot as uploaded by the browser.
 *
 * @internal
 */
final readonly class Screenshot
{
    public function __construct(
        public string $content,
        public string $mediaType,
        public string $fileExtension,
        public int $width,
        public int $height,
        public int $size,
        public string $sha256,
    ) {}

    public function getFilename(string $reportIdentifier): string
    {
        return $reportIdentifier . '-screenshot.' . $this->fileExtension;
    }
}
