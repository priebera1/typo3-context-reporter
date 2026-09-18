<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Report;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Report\InvalidReportInputException;
use Priebera\ContextReporter\Report\ReportInputNormalizer;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ReportInputNormalizerTest extends UnitTestCase
{
    #[Test]
    public function titleIsTrimmedAndFlattenedToOneLine(): void
    {
        self::assertSame('Image picker is empty', ReportInputNormalizer::title("  Image\r\n picker \t is\x07 empty  "));
    }

    #[Test]
    public function descriptionKeepsLineBreaksButDropsControlCharacters(): void
    {
        self::assertSame("Step 1\nStep 2\n\tindented", ReportInputNormalizer::description("  Step 1\r\nStep 2\r\n\tindented\x00\x1B  \n"));
    }

    #[Test]
    public function emptyDescriptionIsAllowed(): void
    {
        self::assertSame('', ReportInputNormalizer::description("  \n "));
    }

    public static function invalidTitleProvider(): \Generator
    {
        yield 'empty' => ['', InvalidReportInputException::TITLE_MISSING];
        yield 'whitespace only' => [" \n\t ", InvalidReportInputException::TITLE_MISSING];
        yield 'too long' => [str_repeat('ä', ReportInputNormalizer::TITLE_MAX_LENGTH + 1), InvalidReportInputException::TITLE_TOO_LONG];
        yield 'invalid utf-8' => ["Broken \xC3\x28", InvalidReportInputException::INVALID_ENCODING];
    }

    #[Test]
    #[DataProvider('invalidTitleProvider')]
    public function invalidTitlesAreRejectedWithReason(string $title, string $reason): void
    {
        try {
            ReportInputNormalizer::title($title);
            self::fail('Expected exception was not thrown.');
        } catch (InvalidReportInputException $exception) {
            self::assertSame($reason, $exception->getReason());
        }
    }

    #[Test]
    public function titleAtMaximumLengthIsAccepted(): void
    {
        $title = str_repeat('ä', ReportInputNormalizer::TITLE_MAX_LENGTH);

        self::assertSame($title, ReportInputNormalizer::title($title));
    }

    #[Test]
    public function tooLongDescriptionIsRejectedInsteadOfTruncated(): void
    {
        $this->expectException(InvalidReportInputException::class);
        ReportInputNormalizer::description(str_repeat('x', ReportInputNormalizer::DESCRIPTION_MAX_LENGTH + 1));
    }
}
