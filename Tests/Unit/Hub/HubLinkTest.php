<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Hub;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Hub\HubLink;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class HubLinkTest extends UnitTestCase
{
    private const URL = 'https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P';

    #[Test]
    public function theHubAppIsOpenedOnTheTrustedAddress(): void
    {
        self::assertSame('https://hub.example.com/app', HubLink::app(self::URL));
        self::assertSame('https://example.com/hub/app', HubLink::app('https://example.com/hub/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P'));
        self::assertSame('http://localhost:3210/app', HubLink::app('http://localhost:3210/'));
        self::assertSame('https://example.com/hub/app', HubLink::app('https://example.com/hub'));
        foreach (['', 'not a url', 'javascript:alert(1)', 'ftp://hub.example.com', 'https://u:p@hub.example.com', 'https://hub.example.com/?x=1'] as $base) {
            self::assertSame('', HubLink::app($base), $base);
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function links(): iterable
    {
        yield 'report' => ['https://hub.example.com/app/reports/LW-1', self::URL, 'https://hub.example.com/app/reports/LW-1'];
        yield 'explicit default port' => ['https://hub.example.com:443/app/inbox', self::URL, 'https://hub.example.com:443/app/inbox'];
        yield 'below a path prefix' => ['https://example.com/hub/app/inbox', 'https://example.com/hub/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P', 'https://example.com/hub/app/inbox'];
        yield 'local development' => ['http://host.docker.internal:3210/app/inbox', 'http://host.docker.internal:3210/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P', 'http://host.docker.internal:3210/app/inbox'];
        yield 'Hub address for links' => ['http://localhost:3210/app/inbox', 'http://localhost:3210', 'http://localhost:3210/app/inbox'];
        yield 'Hub address for links with slash' => ['http://localhost:3210/app/inbox', 'http://localhost:3210/', 'http://localhost:3210/app/inbox'];
        yield 'Hub address for links with path' => ['https://example.com/hub/app/inbox', 'https://example.com/hub', 'https://example.com/hub/app/inbox'];
        yield 'not the Hub address for links' => ['http://host.docker.internal:3210/app/inbox', 'http://localhost:3210', ''];
        yield 'other host' => ['https://evil.example.com/app/inbox', self::URL, ''];
        yield 'sub domain' => ['https://hub.example.com.evil.test/app/inbox', self::URL, ''];
        yield 'other scheme' => ['http://hub.example.com/app/inbox', self::URL, ''];
        yield 'other port' => ['https://hub.example.com:8443/app/inbox', self::URL, ''];
        yield 'credentials' => ['https://user:pass@hub.example.com/app/inbox', self::URL, ''];
        yield 'query' => ['https://hub.example.com/app/inbox?token=1', self::URL, ''];
        yield 'fragment' => ['https://hub.example.com/app/inbox#x', self::URL, ''];
        yield 'outside the app' => ['https://hub.example.com/api/v1/reports', self::URL, ''];
        yield 'outside the prefix' => ['https://example.com/app/inbox', 'https://example.com/hub/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P', ''];
        yield 'dot segments' => ['https://hub.example.com/app/../in/s_x', self::URL, ''];
        yield 'trailing dot segment' => ['https://hub.example.com/app/..', self::URL, ''];
        yield 'encoded dot segment' => ['https://hub.example.com/app/%2e%2e/api/v1/reports', self::URL, ''];
        yield 'javascript' => ['javascript:alert(1)//hub.example.com/app/', self::URL, ''];
        yield 'protocol relative' => ['//hub.example.com/app/inbox', self::URL, ''];
        yield 'quote' => ['https://hub.example.com/app/"onmouseover', self::URL, ''];
    }

    #[Test]
    #[DataProvider('links')]
    public function onlyLinksIntoTheHubAppAreKept(string $link, string $webhookUrl, string $expected): void
    {
        self::assertSame($expected, HubLink::trusted($link, $webhookUrl));
    }
}
