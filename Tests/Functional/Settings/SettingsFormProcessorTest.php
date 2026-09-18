<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Settings;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\ExtensionSettingsFactory;
use Priebera\ContextReporter\Settings\SettingsFormProcessor;
use Priebera\ContextReporter\Settings\SettingsFormResult;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;

final class SettingsFormProcessorTest extends AbstractContextReporterTestCase
{
    #[Test]
    public function generalSettingsAreNormalized(): void
    {
        $result = $this->process('general', [
            'enabled' => '1',
            'projectName' => '  Agency Portal ',
            'projectIdentifier' => 'agency-portal',
            'environment' => 'custom',
            'environmentCustom' => ' QA cluster ',
        ]);

        self::assertTrue($result->isValid());
        self::assertSame(['enabled' => true, 'projectName' => 'Agency Portal', 'projectIdentifier' => 'agency-portal', 'environment' => 'QA cluster'], $result->values);

        $preset = $this->process('general', ['enabled' => '0', 'environment' => 'Staging', 'environmentCustom' => 'ignored']);
        self::assertSame(['enabled' => false, 'projectName' => '', 'projectIdentifier' => '', 'environment' => 'Staging'], $preset->values);
        self::assertSame('', $this->process('general', ['environment' => ''])->values['environment']);
    }

    #[Test]
    public function invalidGeneralSettingsAreRejected(): void
    {
        $result = $this->process('general', [
            'projectName' => str_repeat('x', 101),
            'projectIdentifier' => 'not valid!',
            'environment' => 'custom',
            'environmentCustom' => '',
        ]);

        self::assertFalse($result->isValid());
        self::assertSame(['projectName', 'projectIdentifier', 'environment'], array_keys($result->errors));
        self::assertSame('Production', $this->process('general', ['environment' => 'Production'])->values['environment']);
        self::assertArrayHasKey('environment', $this->process('general', ['environment' => 'Live'])->errors);
    }

    #[Test]
    public function privacyChoicesAreBooleans(): void
    {
        $result = $this->process('privacy', ['reporterUid' => '1', 'reporterEmail' => ['0', '1'], 'browserDetails' => '0', 'unknown' => '1']);

        self::assertSame([
            'reporterUid' => true,
            'reporterUsername' => false,
            'reporterRealName' => false,
            'reporterEmail' => true,
            'reporterGroups' => false,
            'browserDetails' => false,
            'recentBackendErrors' => false,
        ], $result->values);
    }

    #[Test]
    public function retentionIsAPresetOrACustomNumberOfDays(): void
    {
        $values = $this->process('reporting', ['maxScreenshotSizeKb' => '2048', 'maxReportsPerUserPerHour' => '0', 'retention' => '180'])->values;
        self::assertSame(['maxScreenshotSizeKb' => 2048, 'maxReportsPerUserPerHour' => 0, 'retentionDays' => 180], $values);

        self::assertSame(0, $this->process('reporting', ['retention' => '0'])->values['retentionDays']);
        self::assertSame(45, $this->process('reporting', ['retention' => 'custom', 'retentionDays' => '45'])->values['retentionDays']);
        self::assertSame(ExtensionSettingsFactory::DEFAULT_SCREENSHOT_KB, $this->process('reporting', [])->values['maxScreenshotSizeKb']);

        $invalid = $this->process('reporting', ['maxScreenshotSizeKb' => '99999', 'maxReportsPerUserPerHour' => '-1', 'retention' => 'custom', 'retentionDays' => '0']);
        self::assertSame(['maxScreenshotSizeKb', 'maxReportsPerUserPerHour', 'retentionDays'], array_keys($invalid->errors));
        self::assertArrayHasKey('retentionDays', $this->process('reporting', ['retention' => '7'])->errors);
    }

    #[Test]
    public function emailSettingsAreValidated(): void
    {
        $result = $this->process('email', [
            'enabled' => '1',
            'recipients' => "support@example.com;\n dev@example.com, support@example.com",
            'senderAddress' => 'typo3@example.com',
            'senderName' => 'TYPO3 Portal',
            'subject' => '',
            'bodyTemplate' => '',
            'attachScreenshot' => '1',
            'attachJson' => '0',
            'replyToReporter' => '1',
        ]);

        self::assertTrue($result->isValid());
        self::assertSame([
            'enabled' => true,
            'recipients' => 'support@example.com, dev@example.com',
            'senderAddress' => 'typo3@example.com',
            'senderName' => 'TYPO3 Portal',
            'subject' => ExtensionSettingsFactory::DEFAULT_SUBJECT,
            'bodyTemplate' => ExtensionSettingsFactory::DEFAULT_BODY_TEMPLATE,
            'attachScreenshot' => true,
            'attachJson' => false,
            'replyToReporter' => true,
        ], $result->values);

        $invalid = $this->process('email', [
            'enabled' => '1',
            'recipients' => 'not-an-address, ',
            'senderAddress' => 'nobody',
            'senderName' => "Evil\r\nBcc: x@example.com",
            'subject' => "Line\nbreak",
            'bodyTemplate' => 'EXT:context_reporter/Resources/Private/Templates/Email/Missing.txt',
        ]);
        self::assertSame(['recipients', 'senderAddress', 'senderName', 'subject', 'bodyTemplate'], array_keys($invalid->errors));

        foreach (['.env', 'config/system/settings.php', 'EXT:context_reporter/composer.json', 'EXT:context_reporter/Resources/Private/Language/locallang.xlf'] as $path) {
            self::assertArrayHasKey('bodyTemplate', $this->process('email', ['bodyTemplate' => $path])->errors, $path);
        }
        self::assertArrayHasKey('recipients', $this->process('email', ['enabled' => '1', 'recipients' => ''])->errors, 'Enabled email needs a recipient');
        self::assertTrue($this->process('email', ['enabled' => '0', 'recipients' => ''])->isValid());
        self::assertArrayHasKey('recipients', $this->process('email', ['recipients' => implode(',', array_map(static fn(int $i): string => "user$i@example.com", range(1, 21)))])->errors);
    }

    #[Test]
    public function webhookEndpointMustUseHttpsUnlessAllowed(): void
    {
        self::assertSame('https://hooks.example.com/abc', $this->process('webhook', ['enabled' => '1', 'url' => ' https://hooks.example.com/abc '])->values['url']);
        self::assertArrayHasKey('url', $this->process('webhook', ['enabled' => '1', 'url' => 'http://hooks.example.com/abc'])->errors);
        self::assertTrue($this->process('webhook', ['enabled' => '1', 'url' => 'http://receiver.local:8090/', 'allowInsecureHttp' => '1'])->isValid());
        self::assertArrayHasKey('url', $this->process('webhook', ['enabled' => '1', 'url' => 'ftp://example.com'])->errors);
        self::assertArrayHasKey('url', $this->process('webhook', ['enabled' => '1', 'url' => ''])->errors, 'Enabled webhook needs an endpoint');
        self::assertSame('%env(CR_WEBHOOK_URL)%', $this->process('webhook', ['enabled' => '1', 'url' => '%env(CR_WEBHOOK_URL)%'])->values['url']);
        self::assertSame(10, $this->process('webhook', [])->values['timeout']);
        self::assertArrayHasKey('timeout', $this->process('webhook', ['timeout' => '31'])->errors);
        self::assertArrayHasKey('authHeaderName', $this->process('webhook', ['authHeaderName' => 'X Api Key'])->errors);
        self::assertSame('Authorization', $this->process('webhook', ['authHeaderName' => ''])->values['authHeaderName']);
    }

    #[Test]
    public function theStoredEndpointIsWriteOnly(): void
    {
        $current = ['url' => 'https://hooks.example.com/hook/s3cr3t-path'];

        $kept = $this->process('webhook', ['enabled' => '1', 'url' => ''], $current);
        self::assertTrue($kept->isValid());
        self::assertSame('https://hooks.example.com/hook/s3cr3t-path', $kept->values['url']);

        self::assertSame('https://other.example.com/', $this->process('webhook', ['url' => 'https://other.example.com/'], $current)->values['url']);

        $removed = $this->process('webhook', ['enabled' => '0', 'url' => 'https://ignored.example.com/', 'urlRemove' => '1'], $current);
        self::assertTrue($removed->isValid());
        self::assertSame('', $removed->values['url']);
        self::assertArrayHasKey('url', $this->process('webhook', ['enabled' => '1', 'urlRemove' => '1'], $current)->errors);

        $insecure = ['url' => 'http://receiver.local:8090/hook'];
        self::assertTrue($this->process('webhook', ['allowInsecureHttp' => '1'], $insecure)->isValid());
        self::assertArrayHasKey('url', $this->process('webhook', ['allowInsecureHttp' => '0'], $insecure)->errors, 'A stored http endpoint needs the insecure option');
    }

    #[Test]
    public function secretsAreKeptReplacedOrRemoved(): void
    {
        $current = ['secret' => 'old-secret', 'authHeaderValue' => 'Bearer old'];

        $kept = $this->process('webhook', ['secret' => '', 'authHeaderValue' => ''], $current);
        self::assertSame('old-secret', $kept->values['secret']);
        self::assertSame('Bearer old', $kept->values['authHeaderValue']);

        $replaced = $this->process('webhook', ['secret' => ' new-secret ', 'authHeaderValue' => '%env(CR_TOKEN)%'], $current);
        self::assertSame('new-secret', $replaced->values['secret']);
        self::assertSame('%env(CR_TOKEN)%', $replaced->values['authHeaderValue']);

        $removed = $this->process('webhook', ['secret' => 'ignored', 'secretRemove' => '1', 'authHeaderValueRemove' => '1'], $current);
        self::assertSame('', $removed->values['secret']);
        self::assertSame('', $removed->values['authHeaderValue']);

        self::assertArrayHasKey('authHeaderValue', $this->process('webhook', ['authHeaderValue' => "Bearer x\r\nX-Evil: 1"], $current)->errors);
    }

    #[Test]
    public function valuesOfTheSystemConfigurationAreNeitherChangedNorValidated(): void
    {
        $result = $this->process('email', ['enabled' => '1', 'recipients' => 'invalid'], ['enabled' => false, 'recipients' => 'kept@example.com'], ['recipients', 'enabled']);

        self::assertTrue($result->isValid());
        self::assertFalse($result->values['enabled']);
        self::assertSame('kept@example.com', $result->values['recipients']);
    }

    #[Test]
    public function unknownSectionsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->process('licence', []);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     * @param list<string> $overridden
     */
    private function process(string $section, array $input, array $current = [], array $overridden = []): SettingsFormResult
    {
        return (new SettingsFormProcessor())->process($section, $input, $current, $overridden);
    }
}
