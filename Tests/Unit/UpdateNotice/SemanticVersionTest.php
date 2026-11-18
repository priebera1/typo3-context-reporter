<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\UpdateNotice;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\UpdateNotice\SemanticVersion;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SemanticVersionTest extends UnitTestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function comparisons(): iterable
    {
        yield 'same version' => ['0.4.0', '0.4.0', false];
        yield 'next minor' => ['0.5.0', '0.4.0', true];
        yield 'next patch' => ['0.4.1', '0.4.0', true];
        yield 'next major' => ['1.0.0', '0.9.9', true];
        yield 'older (no downgrade)' => ['0.4.0', '0.5.0', false];
        yield 'numeric, not textual' => ['0.10.0', '0.9.0', true];
        yield 'numeric patch' => ['0.4.10', '0.4.9', true];
        yield 'textually larger but older' => ['0.9.0', '0.10.0', false];
    }

    #[Test]
    #[DataProvider('comparisons')]
    public function comparesPartsAsNumbers(string $candidate, string $installed, bool $newer): void
    {
        $left = SemanticVersion::tryParse($candidate);
        $right = SemanticVersion::tryParse($installed);
        self::assertNotNull($left);
        self::assertNotNull($right);
        self::assertSame($newer, $left->isNewerThan($right));
        self::assertSame($candidate === $installed, $left->equals($right));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function versionsWithoutNotice(): iterable
    {
        foreach (['', 'dev-main', '0.4', '0.4.0.0', 'v0.4.0', '0.4.0-beta.1', '0.4.0-rc1', '0.4.0+build.5', '00.4.0', '0.04.0', '0.4.x', "0.4.0\n1", '12345.0.0', ' 0 . 4 . 0 '] as $value) {
            yield var_export($value, true) => [$value];
        }
    }

    #[Test]
    #[DataProvider('versionsWithoutNotice')]
    public function onlyStableReleasesParse(string $value): void
    {
        self::assertNull(SemanticVersion::tryParse($value));
    }

    #[Test]
    public function surroundingWhitespaceIsIgnored(): void
    {
        self::assertSame('0.4.0', SemanticVersion::tryParse(' 0.4.0 ')?->toString());
    }
}
