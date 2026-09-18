<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Configuration;

/**
 * @internal
 */
final readonly class EmailSettings
{
    /**
     * @param list<string> $recipients
     */
    public function __construct(
        public bool $enabled,
        public array $recipients,
        public string $senderAddress,
        public string $senderName,
        public string $subjectTemplate,
        public string $bodyTemplateFile,
        public bool $attachScreenshot,
        public bool $attachJson,
        public bool $replyToReporter,
    ) {}

    public function isUsable(): bool
    {
        return $this->enabled && $this->recipients !== [];
    }
}
