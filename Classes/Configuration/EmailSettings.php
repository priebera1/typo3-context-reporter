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
        /** Sends a formatted HTML part next to the plain text, with the shipped template only (sendsHtmlVersion()) */
        public bool $htmlVersion = true,
    ) {}

    public function isUsable(): bool
    {
        return $this->enabled && $this->recipients !== [];
    }

    /**
     * The plain text comes from the shipped template, not from a template of the installation.
     */
    public function usesShippedTemplate(): bool
    {
        return $this->bodyTemplateFile === ExtensionSettingsFactory::DEFAULT_BODY_TEMPLATE;
    }

    /**
     * The formatted HTML version shows the content of the shipped template. A
     * template of the installation decides what the email contains (e.g. only
     * the title and the link), so it is sent as plain text only: the HTML version
     * never adds what the template leaves out.
     */
    public function sendsHtmlVersion(): bool
    {
        return $this->htmlVersion && $this->usesShippedTemplate();
    }
}
