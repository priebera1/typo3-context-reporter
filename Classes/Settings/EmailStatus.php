<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Settings;

use Priebera\ContextReporter\Delivery\Email\MailTransportDescription;

/**
 * @internal
 */
final readonly class EmailStatus
{
    /**
     * @param list<string> $recipients
     * @param string $senderAddress The address emails are sent from, possibly the TYPO3 default
     * @param list<StatusMessage> $messages
     */
    public function __construct(
        public bool $enabled,
        public array $recipients,
        public string $senderAddress,
        public string $senderName,
        public bool $senderFromTypo3,
        public bool $attachScreenshot,
        public bool $attachJson,
        public bool $replyToReporter,
        public MailTransportDescription $transport,
        public array $messages,
    ) {}

    /**
     * Reports are handed over to the mail transport.
     */
    public function isReady(): bool
    {
        return $this->enabled && $this->recipients !== [] && !$this->hasBlockingMessage();
    }

    public function canSendTest(): bool
    {
        return $this->recipients !== [];
    }

    private function hasBlockingMessage(): bool
    {
        foreach ($this->messages as $message) {
            if ($message->isBlocking()) {
                return true;
            }
        }
        return false;
    }
}
