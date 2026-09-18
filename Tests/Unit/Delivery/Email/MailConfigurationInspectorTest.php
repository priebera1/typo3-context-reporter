<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Delivery\Email;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Delivery\Email\MailConfigurationInspector;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class MailConfigurationInspectorTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['MAIL']);
        putenv('IS_DDEV_PROJECT');
        parent::tearDown();
    }

    #[Test]
    public function smtpTransportIsDescribedWithoutCredentials(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = [
            'transport' => 'smtp',
            'transport_smtp_server' => 'smtp.gmail.com:587',
            'transport_smtp_encrypt' => true,
            'transport_smtp_username' => 'agency@example.com',
            'transport_smtp_password' => 'app-password',
            'defaultMailFromAddress' => 'typo3@example.com',
            'defaultMailFromName' => 'Portal',
        ];
        $inspector = new MailConfigurationInspector();

        $transport = $inspector->describe();

        self::assertSame('smtp', $transport->type);
        self::assertSame('smtp.gmail.com:587', $transport->target);
        self::assertTrue($transport->authentication);
        self::assertTrue($transport->encrypted);
        self::assertSame('', $transport->spool);
        self::assertFalse($transport->localCatcher);
        self::assertSame('typo3@example.com', $transport->defaultSenderAddress);
        self::assertSame('Portal', $transport->defaultSenderName);
        self::assertTrue($transport->defaultSenderConfigured);
        $exported = var_export($transport, true);
        self::assertStringNotContainsString('app-password', $exported);
        self::assertStringNotContainsString('agency@example.com', $exported);
        self::assertSame(['agency@example.com', 'app-password'], $inspector->getSecrets());
    }

    #[Test]
    public function dsnTransportIsReducedToSchemeAndHost(): void
    {
        $dsn = 'smtp://user%40example.com:p%40ss-word@mail.example.com:465?verify_peer=0';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = ['transport' => 'dsn', 'dsn' => $dsn];
        $inspector = new MailConfigurationInspector();

        $transport = $inspector->describe();

        self::assertSame('dsn', $transport->type);
        self::assertSame('smtp://mail.example.com:465', $transport->target);
        self::assertTrue($transport->authentication);
        self::assertStringNotContainsString('p%40ss', var_export($transport, true));
        $secrets = $inspector->getSecrets();
        foreach ([$dsn, 'user%40example.com', 'user@example.com', 'p%40ss-word', 'p@ss-word'] as $secret) {
            self::assertContains($secret, $secrets);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool, string}>
     */
    public static function localMailCatcherProvider(): iterable
    {
        yield 'Mailpit port' => [['transport' => 'smtp', 'transport_smtp_server' => 'localhost:1025'], true, 'localhost:1025'];
        yield 'Mailpit host' => [['transport' => 'smtp', 'transport_smtp_server' => 'mailpit:25'], true, 'mailpit:25'];
        yield 'SMTP without port' => [['transport' => 'smtp', 'transport_smtp_server' => 'mail.example.com'], false, 'mail.example.com:25'];
        yield 'Mailpit sendmail' => [['transport' => 'sendmail', 'transport_sendmail_command' => '/usr/local/bin/mailpit sendmail -t --smtp-addr 127.0.0.1:1025'], true, '/usr/local/bin/mailpit'];
        yield 'Real sendmail' => [['transport' => 'sendmail', 'transport_sendmail_command' => '/usr/sbin/sendmail -t -i'], false, '/usr/sbin/sendmail'];
        yield 'Real SMTP' => [['transport' => 'smtp', 'transport_smtp_server' => 'smtp.example.com:587'], false, 'smtp.example.com:587'];
        yield 'Mailpit DSN' => [['transport' => 'dsn', 'dsn' => 'smtp://127.0.0.1:1025'], true, 'smtp://127.0.0.1:1025'];
        yield 'Credentials in the server setting' => [['transport' => 'smtp', 'transport_smtp_server' => 'mailer:secret@smtp.example.com:587'], false, ''];
    }

    /**
     * @param array<string, mixed> $mailConfiguration
     */
    #[Test]
    #[DataProvider('localMailCatcherProvider')]
    public function localMailCatchersAreDetected(array $mailConfiguration, bool $expected, string $target): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = $mailConfiguration;

        $transport = (new MailConfigurationInspector())->describe();

        self::assertSame($expected, $transport->localCatcher);
        self::assertSame($target, $transport->target);
    }

    #[Test]
    public function spoolAndSpecialTransportsAreReported(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = ['transport' => 'smtp', 'transport_smtp_server' => 'smtp.example.com:587', 'transport_spool_type' => 'file', 'transport_spool_filepath' => '/var/spool/typo3'];
        self::assertSame('file', (new MailConfigurationInspector())->describe()->spool);

        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = ['transport' => 'null'];
        self::assertSame('null', (new MailConfigurationInspector())->describe()->type);

        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = ['transport' => 'mbox', 'transport_mbox_file' => '/var/mail/typo3'];
        $mbox = (new MailConfigurationInspector())->describe();
        self::assertSame('mbox', $mbox->type);
        self::assertSame('', $mbox->target);

        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = ['transport' => 'Vendor\\Mail\\ApiTransport', 'transport_api_key' => 'key-123'];
        $custom = (new MailConfigurationInspector())->describe();
        self::assertSame('custom', $custom->type);
        self::assertSame('Vendor\\Mail\\ApiTransport', $custom->target);

        // TYPO3 prefers a configured DSN over custom transports
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = ['transport' => 'Vendor\\Mail\\ApiTransport', 'dsn' => 'ses+api://KEY:SECRET@default'];
        $dsn = (new MailConfigurationInspector())->describe();
        self::assertSame('dsn', $dsn->type);
        self::assertSame('ses+api://default', $dsn->target);
    }

    #[Test]
    public function missingSenderFallsBackToTheTypo3Default(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = ['transport' => 'sendmail', 'defaultMailFromAddress' => 'not an address'];

        $transport = (new MailConfigurationInspector())->describe();

        self::assertFalse($transport->defaultSenderConfigured);
        self::assertStringStartsWith('no-reply@', $transport->defaultSenderAddress);
        self::assertSame('', $transport->defaultSenderName);
    }

    #[Test]
    public function ddevProjectsAreRecognized(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = ['transport' => 'sendmail'];
        self::assertFalse((new MailConfigurationInspector())->describe()->ddev);

        putenv('IS_DDEV_PROJECT=true');
        self::assertTrue((new MailConfigurationInspector())->describe()->ddev);
    }
}
