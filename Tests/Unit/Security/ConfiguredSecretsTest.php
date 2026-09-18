<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Delivery\Email\MailConfigurationInspector;
use Priebera\ContextReporter\Security\ConfiguredSecrets;
use Priebera\ContextReporter\Tests\Unit\Fixtures\SettingsProviderStub;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ConfiguredSecretsTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['MAIL'], $GLOBALS['TYPO3_CONF_VARS']['DB']);
        parent::tearDown();
    }

    #[Test]
    public function webhookMailAndDatabaseCredentialsAreCollected(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = ['transport' => 'smtp', 'transport_smtp_server' => 'mail.example.com:587', 'transport_smtp_username' => 'mailer-user', 'transport_smtp_password' => 'mailer-password'];
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'] = [
            'Default' => ['driver' => 'mysqli', 'user' => 'typo3', 'password' => 'database-password'],
            'Logging' => ['driver' => 'mysqli', 'user' => 'logger', 'password' => ''],
        ];
        $secrets = new ConfiguredSecrets(
            new SettingsProviderStub(['webhook' => ['url' => 'https://hooks.example.com/webhook/secret-path-value', 'secret' => 'hmac-secret-value']]),
            new MailConfigurationInspector(),
        );

        $values = $secrets->get();

        foreach (['hmac-secret-value', 'https://hooks.example.com/webhook/secret-path-value', 'mailer-user', 'mailer-password', 'database-password'] as $secret) {
            self::assertContains($secret, $values);
        }
        self::assertNotContains('typo3', $values, 'Database user names are too common to be redacted');
        self::assertNotContains('', $values);
    }
}
