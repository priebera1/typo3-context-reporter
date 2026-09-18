<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery\Email;

use Symfony\Component\Mailer\Transport\NullTransport;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MailUtility;

/**
 * Explains the TYPO3 mail configuration ($GLOBALS['TYPO3_CONF_VARS']['MAIL'])
 * without revealing credentials, and names the credentials that must be
 * redacted from transport error messages.
 *
 * @internal
 */
final class MailConfigurationInspector
{
    private const CATCHER_PORT = 1025;
    private const CATCHER_NAMES = ['mailpit', 'mailhog', 'mailcatcher', 'mhsendmail', 'catchmail'];

    public function describe(): MailTransportDescription
    {
        $mail = $this->getMailConfiguration();
        $type = $this->getTransportType($mail);
        $target = '';
        $authentication = false;
        $encrypted = false;
        $localCatcher = false;

        switch ($type) {
            case 'smtp':
                $server = $this->string($mail, 'transport_smtp_server');
                // "user:password@host" is not supported by TYPO3; never show it
                [$host, $port] = str_contains($server, '@') ? ['', 0] : $this->splitServer($server);
                $target = $host !== '' ? $host . ':' . $port : '';
                $authentication = $this->string($mail, 'transport_smtp_username') !== '';
                $encrypted = (bool)($mail['transport_smtp_encrypt'] ?? false);
                $localCatcher = $this->isCatcher($host, $port);
                break;
            case 'sendmail':
                $command = $this->string($mail, 'transport_sendmail_command');
                if ($command === '') {
                    $command = trim((string)ini_get('sendmail_path')) ?: '/usr/sbin/sendmail -bs';
                }
                // Only the binary: arguments are not needed to recognize the transport
                $target = (string)strtok($command, " \t");
                $localCatcher = $this->containsCatcherName($command);
                break;
            case 'dsn':
                $parts = parse_url($this->string($mail, 'dsn'));
                if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                    $target = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
                    $authentication = ($parts['user'] ?? '') !== '' || ($parts['pass'] ?? '') !== '';
                    $encrypted = strtolower($parts['scheme']) === 'smtps';
                    $localCatcher = $this->isCatcher($parts['host'], (int)($parts['port'] ?? 0));
                }
                break;
            case 'custom':
                $target = $this->string($mail, 'transport');
                break;
        }

        $configuredSender = $this->string($mail, 'defaultMailFromAddress');
        return new MailTransportDescription(
            type: $type,
            target: $target,
            authentication: $authentication,
            encrypted: $encrypted,
            spool: $this->string($mail, 'transport_spool_type'),
            localCatcher: $localCatcher,
            ddev: getenv('IS_DDEV_PROJECT') === 'true',
            defaultSenderAddress: MailUtility::getSystemFromAddress(),
            defaultSenderName: MailUtility::getSystemFromName() ?? '',
            defaultSenderConfigured: $configuredSender !== '' && GeneralUtility::validEmail($configuredSender),
        );
    }

    /**
     * Credentials of the mail transport. Transport errors can contain them,
     * e.g. "Failed to authenticate on SMTP server with username ...".
     *
     * @return list<string>
     */
    public function getSecrets(): array
    {
        $mail = $this->getMailConfiguration();
        $secrets = [
            $this->string($mail, 'transport_smtp_username'),
            $this->string($mail, 'transport_smtp_password'),
        ];
        $dsn = $this->string($mail, 'dsn');
        if ($dsn !== '') {
            $secrets[] = $dsn;
            $parts = parse_url($dsn);
            foreach (['user', 'pass'] as $key) {
                $value = is_array($parts) ? (string)($parts[$key] ?? '') : '';
                array_push($secrets, $value, rawurldecode($value));
            }
        }
        return array_values(array_unique(array_filter($secrets, static fn(string $secret): bool => $secret !== '')));
    }

    /**
     * Mirrors the transport selection of \TYPO3\CMS\Core\Mail\TransportFactory.
     *
     * @param array<array-key, mixed> $mail
     */
    private function getTransportType(array $mail): string
    {
        $transport = is_string($mail['transport'] ?? null) ? $mail['transport'] : 'sendmail';
        return match (true) {
            in_array($transport, ['smtp', 'sendmail', 'mbox'], true) => $transport,
            $transport === 'null' || $transport === NullTransport::class => 'null',
            $transport === '' || $transport === 'dsn' || $this->string($mail, 'dsn') !== '' => 'dsn',
            default => 'custom',
        };
    }

    /**
     * @return array{string, int}
     */
    private function splitServer(string $server): array
    {
        $parts = GeneralUtility::trimExplode(':', $server, true);
        return [$parts[0] ?? '', isset($parts[1]) ? (int)$parts[1] : 25];
    }

    private function isCatcher(string $host, int $port): bool
    {
        return $port === self::CATCHER_PORT || $this->containsCatcherName($host);
    }

    private function containsCatcherName(string $value): bool
    {
        $value = strtolower($value);
        foreach (self::CATCHER_NAMES as $name) {
            if (str_contains($value, $name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function getMailConfiguration(): array
    {
        $mail = $GLOBALS['TYPO3_CONF_VARS']['MAIL'] ?? null;
        return is_array($mail) ? $mail : [];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }
}
