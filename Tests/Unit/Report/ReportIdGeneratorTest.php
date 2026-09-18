<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Report;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Report\ReportIdGenerator;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ReportIdGeneratorTest extends UnitTestCase
{
    #[Test]
    public function generatedIdentifiersAreReadableAndValid(): void
    {
        $generator = new ReportIdGenerator();
        $identifier = $generator->generate();

        self::assertMatchesRegularExpression('/^CR-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/', $identifier);
        self::assertTrue(ReportIdGenerator::isValid($identifier));
    }

    #[Test]
    public function generatedIdentifiersDoNotRepeat(): void
    {
        $generator = new ReportIdGenerator();
        $identifiers = [];
        for ($i = 0; $i < 2000; $i++) {
            $identifiers[] = $generator->generate();
        }

        self::assertCount(2000, array_unique($identifiers));
    }

    public static function invalidIdentifierProvider(): \Generator
    {
        yield 'empty' => [''];
        yield 'lower case' => ['cr-abcd-efgh-jkmn'];
        yield 'ambiguous letter I' => ['CR-IIII-0000-0000'];
        yield 'too short' => ['CR-0000-0000'];
        yield 'injection' => ["CR-0000-0000-0000\n"];
        yield 'sql' => ["CR-0000-0000-000' OR 1=1"];
    }

    #[Test]
    #[DataProvider('invalidIdentifierProvider')]
    public function invalidIdentifiersAreRejected(string $identifier): void
    {
        self::assertFalse(ReportIdGenerator::isValid($identifier));
    }
}
