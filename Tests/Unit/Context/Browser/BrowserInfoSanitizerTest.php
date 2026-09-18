<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Context\Browser;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\Browser\BrowserInfoSanitizer;
use Priebera\ContextReporter\Context\Browser\UserAgentParser;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class BrowserInfoSanitizerTest extends UnitTestCase
{
    private const CHROME_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

    #[Test]
    public function allowlistedValuesAreKeptAndSummarized(): void
    {
        $result = $this->createSanitizer()->sanitize([
            'userAgent' => self::CHROME_MAC,
            'platform' => 'macOS',
            'mobile' => false,
            'touch' => false,
            'language' => 'de-AT',
            'languages' => ['de-AT', 'de', 'en'],
            'timeZone' => 'Europe/Vienna',
            'viewport' => ['width' => 1440, 'height' => 900],
            'screen' => ['width' => 2560, 'height' => 1440],
            'devicePixelRatio' => 2,
            'colorScheme' => 'dark',
            'backendColorScheme' => 'auto',
            'reducedMotion' => true,
        ]);

        self::assertSame([
            'summary' => 'Chrome 128 · macOS · 1440×900 @2x',
            'name' => 'Chrome',
            'version' => '128',
            'os' => 'macOS',
            'userAgent' => self::CHROME_MAC,
            'platform' => 'macOS',
            'mobile' => false,
            'touch' => false,
            'language' => 'de-AT',
            'languages' => ['de-AT', 'de', 'en'],
            'timeZone' => 'Europe/Vienna',
            'viewport' => ['width' => 1440, 'height' => 900],
            'screen' => ['width' => 2560, 'height' => 1440],
            'devicePixelRatio' => 2.0,
            'colorScheme' => 'dark',
            'backendColorScheme' => 'auto',
            'reducedMotion' => true,
        ], $result);
    }

    #[Test]
    public function unknownKeysAreDroppedSoTheBrowserCannotSmuggleData(): void
    {
        $result = $this->createSanitizer()->sanitize([
            'cookie' => 'be_typo_user=abc',
            'localStorage' => ['token' => 'x'],
            'language' => 'en',
        ]);

        self::assertArrayNotHasKey('cookie', $result);
        self::assertArrayNotHasKey('localStorage', $result);
        self::assertSame('en', $result['language']);
    }

    #[Test]
    public function invalidValuesAreDroppedOrBounded(): void
    {
        $result = $this->createSanitizer()->sanitize([
            'userAgent' => str_repeat('A', 2000) . "\x00",
            'language' => 'de"><script>',
            'languages' => ['en', 'x"y', ['nested'], 'fr', 'it', 'es', 'pt', 'nl'],
            'timeZone' => 'Europe/Vienna; DROP TABLE',
            'viewport' => ['width' => -5, 'height' => 999999],
            'screen' => 'big',
            'devicePixelRatio' => 'NaN',
            'colorScheme' => 'purple',
            'backendColorScheme' => 'hacker',
            'mobile' => 'yes',
        ]);

        self::assertSame(512, strlen($result['userAgent']));
        self::assertArrayNotHasKey('language', $result);
        self::assertSame(['en', 'fr', 'it', 'es', 'pt'], $result['languages']);
        self::assertArrayNotHasKey('timeZone', $result);
        self::assertSame(['width' => 0, 'height' => 20000], $result['viewport']);
        self::assertArrayNotHasKey('screen', $result);
        self::assertArrayNotHasKey('devicePixelRatio', $result);
        self::assertArrayNotHasKey('colorScheme', $result);
        self::assertArrayNotHasKey('backendColorScheme', $result);
        self::assertArrayNotHasKey('mobile', $result);
    }

    #[Test]
    public function emptyInputProducesEmptyResult(): void
    {
        self::assertSame([], $this->createSanitizer()->sanitize([]));
    }

    private function createSanitizer(): BrowserInfoSanitizer
    {
        return new BrowserInfoSanitizer(new UserAgentParser());
    }
}
