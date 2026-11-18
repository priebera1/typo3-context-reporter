<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\UpdateNotice;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\UpdateNotice\LatestRelease;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class LatestReleaseTest extends UnitTestCase
{
    #[Test]
    public function readsTheReleaseObjectOfTheContract(): void
    {
        $release = LatestRelease::fromArray([
            'product' => 'context-reporter',
            'channel' => 'stable',
            'version' => '0.5.0',
            'releasedAt' => '2027-01-15',
            'releaseUrl' => 'https://github.com/priebera1/typo3-context-reporter/blob/0.5.0/CHANGELOG.md',
            'addedLater' => ['ignored' => true],
        ]);
        self::assertNotNull($release);
        self::assertSame([
            'version' => '0.5.0',
            'releasedAt' => '2027-01-15',
            'releaseUrl' => 'https://github.com/priebera1/typo3-context-reporter/blob/0.5.0/CHANGELOG.md',
        ], $release->toArray());
        self::assertEquals($release, LatestRelease::fromArray($release->toArray()));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function rejectedReleases(): iterable
    {
        yield 'not an object' => ['0.5.0'];
        yield 'no version' => [['releaseUrl' => 'https://github.com/x']];
        yield 'numeric version' => [['version' => 5]];
        yield 'pre-release' => [['version' => '0.5.0-beta.1']];
        yield 'branch' => [['version' => 'dev-main']];
    }

    #[Test]
    #[DataProvider('rejectedReleases')]
    public function anAnswerWithoutAStableVersionIsNoRelease(mixed $release): void
    {
        self::assertNull(LatestRelease::fromArray($release));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function untrustedLinks(): iterable
    {
        yield 'missing' => [null];
        yield 'http' => ['http://github.com/priebera1'];
        yield 'other host' => ['https://evil.example/changelog'];
        yield 'look-alike host' => ['https://github.com.evil.example/x'];
        yield 'credentials' => ['https://user:secret@github.com/x'];
        yield 'port' => ['https://github.com:8443/x'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'markup' => ['https://github.com/x"><script>'];
        yield 'too long' => ['https://github.com/' . str_repeat('a', 600)];
        yield 'not a string' => [['https://github.com/x']];
    }

    #[Test]
    #[DataProvider('untrustedLinks')]
    public function untrustedLinksAreReplacedByTheTerPage(mixed $link): void
    {
        self::assertSame(LatestRelease::FALLBACK_RELEASE_URL, LatestRelease::fromArray(['version' => '0.5.0', 'releaseUrl' => $link])?->releaseUrl);
    }

    #[Test]
    public function invalidDatesAreDropped(): void
    {
        foreach (['2027-02-30', '15.01.2027', '2027-01-15T10:00:00Z', 20270115, ''] as $date) {
            self::assertSame('', LatestRelease::fromArray(['version' => '0.5.0', 'releasedAt' => $date])?->releasedAt);
        }
    }
}
