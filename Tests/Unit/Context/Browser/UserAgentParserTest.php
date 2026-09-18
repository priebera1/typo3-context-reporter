<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Context\Browser;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\Browser\UserAgentParser;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class UserAgentParserTest extends UnitTestCase
{
    public static function userAgentProvider(): \Generator
    {
        yield 'Chrome on Windows' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
            '',
            ['name' => 'Chrome', 'version' => '129', 'os' => 'Windows'],
        ];
        yield 'Edge on Windows' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.2792.52',
            'Windows',
            ['name' => 'Edge', 'version' => '129', 'os' => 'Windows'],
        ];
        yield 'Firefox on Linux' => [
            'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0',
            '',
            ['name' => 'Firefox', 'version' => '130', 'os' => 'Linux'],
        ];
        yield 'Safari on macOS' => [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15',
            '',
            ['name' => 'Safari', 'version' => '17.6', 'os' => 'macOS'],
        ];
        yield 'Safari on iPad' => [
            'Mozilla/5.0 (iPad; CPU OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1',
            '',
            ['name' => 'Safari', 'version' => '17.6', 'os' => 'iPadOS'],
        ];
        yield 'Opera' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 OPR/114.0.0.0',
            '',
            ['name' => 'Opera', 'version' => '114', 'os' => 'Windows'],
        ];
        yield 'Chrome on Android' => [
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36',
            '',
            ['name' => 'Chrome', 'version' => '129', 'os' => 'Android 14'],
        ];
        yield 'client hint platform wins' => [
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
            'Chrome OS',
            ['name' => 'Chrome', 'version' => '129', 'os' => 'Chrome OS'],
        ];
        yield 'unknown' => ['curl/8.0', '', ['name' => '', 'version' => '', 'os' => '']];
    }

    /**
     * @param array{name: string, version: string, os: string} $expected
     */
    #[Test]
    #[DataProvider('userAgentProvider')]
    public function commonBrowsersAreDetected(string $userAgent, string $platformHint, array $expected): void
    {
        self::assertSame($expected, (new UserAgentParser())->parse($userAgent, $platformHint));
    }
}
