<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Fixtures;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use TYPO3\CMS\Core\Mail\MailerInterface;

/**
 * Keeps sent emails in memory, or fails with the given exception.
 */
final class RecordingMailer implements MailerInterface
{
    /**
     * @var list<Email>
     */
    public array $messages = [];

    public function __construct(
        private readonly ?\Throwable $failure = null,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if ($message instanceof Email) {
            $this->messages[] = $message;
        }
    }

    public function getSentMessage(): ?SentMessage
    {
        return null;
    }

    public function getTransport(): TransportInterface
    {
        throw new \LogicException('Not available in tests', 1757930501);
    }

    public function getRealTransport(): TransportInterface
    {
        throw new \LogicException('Not available in tests', 1757930502);
    }
}
