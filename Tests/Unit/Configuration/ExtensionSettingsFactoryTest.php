<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\ExtensionSettingsFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ExtensionSettingsFactoryTest extends UnitTestCase
{
    #[Test]
    public function missingConfigurationFallsBackToSafeDefaults(): void
    {
        $settings = $this->createFactory()->fromArray([], 'My Site', 'Production');

        self::assertTrue($settings->enabled);
        self::assertSame('My Site', $settings->projectName);
        self::assertSame('Production', $settings->environment);
        self::assertSame('', $settings->projectIdentifier);
        self::assertSame(5120 * 1024, $settings->maxScreenshotBytes);
        self::assertSame(20, $settings->maxReportsPerUserPerHour);
        self::assertSame(0, $settings->retentionDays, 'Reports are kept forever unless configured otherwise');
        self::assertTrue($settings->includeBrowserDetails);
        self::assertFalse($settings->includeRecentBackendErrors);

        self::assertTrue($settings->reporterPrivacy->includeUid);
        self::assertTrue($settings->reporterPrivacy->includeUsername);
        self::assertFalse($settings->reporterPrivacy->includeRealName);
        self::assertFalse($settings->reporterPrivacy->includeEmail);
        self::assertFalse($settings->reporterPrivacy->includeGroups);

        self::assertFalse($settings->email->isUsable());
        self::assertFalse($settings->webhook->isUsable());
    }

    #[Test]
    public function configuredValuesAreApplied(): void
    {
        $settings = $this->createFactory()->fromArray([
            'general' => [
                'enabled' => '0',
                'projectName' => ' Agency Portal ',
                'projectIdentifier' => 'agency-portal',
                'environment' => 'Staging',
            ],
            'reporting' => [
                'maxScreenshotSizeKb' => '2048',
                'maxReportsPerUserPerHour' => '0',
                'retentionDays' => '90',
            ],
            'privacy' => [
                'reporterUid' => '0',
                'reporterUsername' => '0',
                'reporterRealName' => '1',
                'reporterEmail' => '1',
                'reporterGroups' => '1',
                'browserDetails' => '0',
                'recentBackendErrors' => '1',
            ],
        ], 'Fallback', 'Production');

        self::assertFalse($settings->enabled);
        self::assertSame('Agency Portal', $settings->projectName);
        self::assertSame('agency-portal', $settings->projectIdentifier);
        self::assertSame('Staging', $settings->environment);
        self::assertSame(2048 * 1024, $settings->maxScreenshotBytes);
        self::assertSame(0, $settings->maxReportsPerUserPerHour);
        self::assertSame(90, $settings->retentionDays);
        self::assertFalse($settings->includeBrowserDetails);
        self::assertTrue($settings->includeRecentBackendErrors);
        self::assertFalse($settings->reporterPrivacy->includeUid);
        self::assertFalse($settings->reporterPrivacy->includeUsername);
        self::assertTrue($settings->reporterPrivacy->includeRealName);
        self::assertTrue($settings->reporterPrivacy->includeEmail);
        self::assertTrue($settings->reporterPrivacy->includeGroups);
    }

    #[Test]
    public function screenshotSizeIsClampedToASaneRange(): void
    {
        $factory = $this->createFactory();

        self::assertSame(100 * 1024, $factory->fromArray(['reporting' => ['maxScreenshotSizeKb' => '1']], '', '')->maxScreenshotBytes);
        self::assertSame(15360 * 1024, $factory->fromArray(['reporting' => ['maxScreenshotSizeKb' => '999999']], '', '')->maxScreenshotBytes);
    }

    #[Test]
    public function projectValuesAreSingleLine(): void
    {
        $settings = $this->createFactory()->fromArray([
            'general' => ['projectName' => "Portal\r\nBcc: x@example.com", 'projectIdentifier' => "id\nx", 'environment' => "Stage\tA\nB"],
        ], 'Site', 'Production');

        self::assertSame('Portal Bcc: x@example.com', $settings->projectName);
        self::assertSame('id x', $settings->projectIdentifier);
        self::assertSame('Stage A B', $settings->environment);
    }

    #[Test]
    public function retentionIsZeroOrAPositiveNumberOfDays(): void
    {
        $factory = $this->createFactory();

        self::assertSame(365, $factory->fromArray(['reporting' => ['retentionDays' => 365]], '', '')->retentionDays);
        self::assertSame(0, $factory->fromArray(['reporting' => ['retentionDays' => '-5']], '', '')->retentionDays);
        self::assertSame(0, $factory->fromArray(['reporting' => ['retentionDays' => 'forever']], '', '')->retentionDays);
        self::assertSame(3650, $factory->fromArray(['reporting' => ['retentionDays' => 99999]], '', '')->retentionDays);
    }

    #[Test]
    public function typedValuesFromTheSettingsModuleAreAccepted(): void
    {
        $settings = $this->createFactory()->fromArray([
            'general' => ['enabled' => false],
            'reporting' => ['maxReportsPerUserPerHour' => 5],
            'email' => ['enabled' => true, 'recipients' => 'support@example.com', 'attachJson' => false],
        ], '', '');

        self::assertFalse($settings->enabled);
        self::assertSame(5, $settings->maxReportsPerUserPerHour);
        self::assertTrue($settings->email->isUsable());
        self::assertFalse($settings->email->attachJson);
    }

    #[Test]
    public function emailRecipientsAreParsedAndInvalidAddressesDropped(): void
    {
        $settings = $this->createFactory()->fromArray([
            'email' => [
                'enabled' => '1',
                'recipients' => 'support@example.com; not-an-address, dev@example.org ,,support@example.com',
                'senderAddress' => 'typo3@example.com',
                'senderName' => 'TYPO3',
                'subject' => '[{project.name}] {report.title}',
                'bodyTemplate' => 'EXT:context_reporter/Resources/Private/Templates/Email/Report.txt',
                'attachScreenshot' => '0',
                'attachJson' => '1',
                'replyToReporter' => '1',
            ],
        ], '', '');

        self::assertTrue($settings->email->isUsable());
        self::assertSame(['support@example.com', 'dev@example.org'], $settings->email->recipients);
        self::assertSame('typo3@example.com', $settings->email->senderAddress);
        self::assertSame('TYPO3', $settings->email->senderName);
        self::assertSame('[{project.name}] {report.title}', $settings->email->subjectTemplate);
        self::assertFalse($settings->email->attachScreenshot);
        self::assertTrue($settings->email->attachJson);
        self::assertTrue($settings->email->replyToReporter);
    }

    #[Test]
    public function emailWithoutValidRecipientsIsNotUsable(): void
    {
        $settings = $this->createFactory()->fromArray([
            'email' => ['enabled' => '1', 'recipients' => 'nobody'],
        ], '', '');

        self::assertFalse($settings->email->isUsable());
    }

    #[Test]
    public function emailSenderAddressMustBeValid(): void
    {
        $settings = $this->createFactory()->fromArray([
            'email' => ['enabled' => '1', 'recipients' => 'a@example.com', 'senderAddress' => 'invalid'],
        ], '', '');

        self::assertSame('', $settings->email->senderAddress);
    }

    #[Test]
    public function webhookRequiresHttpsUnlessInsecureHttpIsAllowed(): void
    {
        $factory = $this->createFactory();

        $https = $factory->fromArray(['webhook' => ['enabled' => '1', 'url' => 'https://hooks.example.com/report']], '', '');
        self::assertTrue($https->webhook->isUsable());
        self::assertSame('https://hooks.example.com/report', $https->webhook->url);

        $http = $factory->fromArray(['webhook' => ['enabled' => '1', 'url' => 'http://n8n.local/webhook/abc']], '', '');
        self::assertFalse($http->webhook->isUsable());
        self::assertSame('', $http->webhook->url);

        $allowed = $factory->fromArray(['webhook' => ['enabled' => '1', 'url' => 'http://n8n.local/webhook/abc', 'allowInsecureHttp' => '1']], '', '');
        self::assertTrue($allowed->webhook->isUsable());
    }

    public static function invalidWebhookUrlProvider(): \Generator
    {
        yield 'empty' => [''];
        yield 'relative' => ['/webhook'];
        yield 'ftp scheme' => ['ftp://example.com/hook'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'missing host' => ['https:///hook'];
        yield 'whitespace inside' => ['https://example.com/ho ok'];
    }

    #[Test]
    #[DataProvider('invalidWebhookUrlProvider')]
    public function invalidWebhookUrlsAreRejected(string $url): void
    {
        $settings = $this->createFactory()->fromArray(['webhook' => ['enabled' => '1', 'url' => $url]], '', '');

        self::assertFalse($settings->webhook->isUsable());
    }

    #[Test]
    public function webhookSecretsCanBeReadFromEnvironmentVariables(): void
    {
        $factory = $this->createFactory([
            'CR_WEBHOOK_SECRET' => 'from-env',
            'CR_WEBHOOK_TOKEN' => 'Bearer env-token',
        ]);

        $settings = $factory->fromArray([
            'webhook' => [
                'enabled' => '1',
                'url' => 'https://hooks.example.com',
                'secret' => '%env(CR_WEBHOOK_SECRET)%',
                'authHeaderName' => 'Authorization',
                'authHeaderValue' => '%env(CR_WEBHOOK_TOKEN)%',
            ],
        ], '', '');

        self::assertSame('from-env', $settings->webhook->secret);
        self::assertSame('Bearer env-token', $settings->webhook->authHeaderValue);
    }

    #[Test]
    public function unsetEnvironmentVariableResolvesToEmptySecret(): void
    {
        $settings = $this->createFactory()->fromArray([
            'webhook' => ['enabled' => '1', 'url' => 'https://hooks.example.com', 'secret' => '%env(DOES_NOT_EXIST)%'],
        ], '', '');

        self::assertSame('', $settings->webhook->secret);
    }

    #[Test]
    public function webhookUrlCanBeReadFromEnvironmentVariable(): void
    {
        $settings = $this->createFactory(['CR_WEBHOOK_URL' => 'https://hooks.example.com/abc'])->fromArray([
            'webhook' => ['enabled' => '1', 'url' => '%env(CR_WEBHOOK_URL)%'],
        ], '', '');

        self::assertSame('https://hooks.example.com/abc', $settings->webhook->url);
    }

    #[Test]
    public function invalidAuthorizationHeaderNameDisablesTheHeader(): void
    {
        $settings = $this->createFactory()->fromArray([
            'webhook' => [
                'enabled' => '1',
                'url' => 'https://hooks.example.com',
                'authHeaderName' => "X-Evil\r\nInjected: yes",
                'authHeaderValue' => 'secret',
            ],
        ], '', '');

        self::assertSame('', $settings->webhook->authHeaderName);
        self::assertSame('', $settings->webhook->authHeaderValue);
    }

    #[Test]
    public function webhookTimeoutIsClamped(): void
    {
        $factory = $this->createFactory();

        self::assertSame(10, $factory->fromArray([], '', '')->webhook->timeoutSeconds);
        self::assertSame(1, $factory->fromArray(['webhook' => ['timeout' => '0']], '', '')->webhook->timeoutSeconds);
        self::assertSame(30, $factory->fromArray(['webhook' => ['timeout' => '300']], '', '')->webhook->timeoutSeconds);
    }

    /**
     * @param array<string, string> $environment
     */
    private function createFactory(array $environment = []): ExtensionSettingsFactory
    {
        return new ExtensionSettingsFactory(
            static fn(string $name): string|false => $environment[$name] ?? false,
        );
    }
}
