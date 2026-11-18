<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\HubIntake;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class HubIntakeTest extends UnitTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function intakeUrlProvider(): iterable
    {
        yield 'hosted Hub' => ['https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P'];
        yield 'local Hub' => ['http://localhost:3210/in/s_demoWebsiteLocalDev000001'];
        yield 'local Hub from a container' => ['http://host.docker.internal:3210/in/s_demoWebsiteLocalDev000001'];
        yield 'Hub behind a path prefix' => ['https://tools.example.com/hub/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P'];
    }

    #[Test]
    #[DataProvider('intakeUrlProvider')]
    public function webhookUrlsOfHubSitesAreAccepted(string $url): void
    {
        self::assertTrue(HubIntake::isIntakeUrl($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUrlProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'inbox link' => ['https://hub.example.com/app/inbox'];
        yield 'report link' => ['https://hub.example.com/app/reports/LW-152'];
        yield 'token too short' => ['https://hub.example.com/in/s_short'];
        yield 'token with other characters' => ['https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3-d9P'];
        yield 'trailing slash' => ['https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P/'];
        yield 'query' => ['https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P?debug=1'];
        yield 'fragment' => ['https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P#x'];
        yield 'credentials' => ['https://user:pass@hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P'];
        yield 'other scheme' => ['ftp://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P'];
        yield 'no host' => ['/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P'];
        yield 'whitespace' => ['https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P x'];
        yield 'signing secret' => ['whsec_localDevSecretDemoWebsite0000000001'];
    }

    #[Test]
    #[DataProvider('invalidUrlProvider')]
    public function otherUrlsAreRejected(string $url): void
    {
        self::assertFalse(HubIntake::isIntakeUrl($url));
    }

    #[Test]
    public function aLargeUploadGetsTimeOfItsOwnWithinTheMaximum(): void
    {
        self::assertSame((float)HubIntake::TIMEOUT_SECONDS, HubIntake::deadlineSeconds(0));
        self::assertSame((float)HubIntake::TIMEOUT_SECONDS, HubIntake::deadlineSeconds(HubIntake::UPLOAD_INCLUDED_BYTES));
        self::assertSame(HubIntake::TIMEOUT_SECONDS + 1.0, HubIntake::deadlineSeconds(HubIntake::UPLOAD_INCLUDED_BYTES + HubIntake::UPLOAD_BYTES_PER_SECOND));
        // The default maximum screenshot (5 MB) makes a body of about 6.8 MB: deliverable over about 2 Mbit/s
        // (its upload at 2.1 Mbit/s fits the limit).
        $body = 6_800_000;
        self::assertGreaterThanOrEqual(($body * 8) / 2_100_000, HubIntake::deadlineSeconds($body));
        self::assertLessThanOrEqual((float)HubIntake::MAX_TIMEOUT_SECONDS, HubIntake::deadlineSeconds($body));
        self::assertSame((float)HubIntake::MAX_TIMEOUT_SECONDS, HubIntake::deadlineSeconds(22 * 1048576));
    }

    #[Test]
    public function signingSecretsHaveTheHubFormat(): void
    {
        self::assertTrue(HubIntake::isSigningSecret('whsec_localDevSecretDemoWebsite0000000001'));
        self::assertTrue(HubIntake::isSigningSecret('whsec_' . str_repeat('A', 32)));
        self::assertFalse(HubIntake::isSigningSecret(''));
        self::assertFalse(HubIntake::isSigningSecret('whsec_short'));
        self::assertFalse(HubIntake::isSigningSecret('localDevSecretDemoWebsite0000000001'));
        self::assertFalse(HubIntake::isSigningSecret('whsec_localDevSecretDemoWebsite 0000000001'));
        self::assertFalse(HubIntake::isSigningSecret("whsec_localDevSecretDemoWebsite0000000001\n"));
        self::assertFalse(HubIntake::isSigningSecret('https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P'));
    }

    #[Test]
    public function theSiteTokenIsOnlyTakenFromHubIntakeUrls(): void
    {
        self::assertSame('s_8Fq2LmZx4RtY7vB1nKc3Wd9P', HubIntake::siteToken('https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P'));
        self::assertSame('s_8Fq2LmZx4RtY7vB1nKc3Wd9P', HubIntake::siteToken('https://example.com/hub/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P'));
        self::assertSame('', HubIntake::siteToken('https://hooks.example.com/webhook/s_8Fq2LmZx4RtY7vB1nKc3Wd9P'));
        self::assertSame('', HubIntake::siteToken('https://hub.example.com/in/s_short'));
        self::assertSame('', HubIntake::siteToken(''));
    }
}
