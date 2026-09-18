<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery\Email;

/**
 * What the TYPO3 mail configuration does with an email, without credentials.
 *
 * @internal
 */
final readonly class MailTransportDescription
{
    /**
     * @param string $type smtp, sendmail, mbox, null, dsn or custom
     * @param string $target Host and port, sendmail binary, DSN scheme and host, or transport class; never credentials
     * @param string $spool Spool type ("file", "memory" or a class name), empty when emails are sent immediately
     * @param bool $localCatcher The target looks like a local mail catcher such as Mailpit or MailHog
     */
    public function __construct(
        public string $type,
        public string $target,
        public bool $authentication,
        public bool $encrypted,
        public string $spool,
        public bool $localCatcher,
        public bool $ddev,
        public string $defaultSenderAddress,
        public string $defaultSenderName,
        public bool $defaultSenderConfigured,
    ) {}
}
