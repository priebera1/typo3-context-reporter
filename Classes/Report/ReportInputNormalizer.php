<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Report;

/**
 * Validates the text the reporter typed. Over-long input is rejected, never
 * silently truncated; the dialog enforces the same limits.
 *
 * @internal
 */
final class ReportInputNormalizer
{
    public const TITLE_MAX_LENGTH = 150;
    public const DESCRIPTION_MAX_LENGTH = 5000;

    /**
     * @throws InvalidReportInputException
     */
    public static function title(string $title): string
    {
        self::assertValidEncoding($title);
        $title = (string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $title);
        $title = trim((string)preg_replace('/ {2,}/', ' ', $title));
        if ($title === '') {
            throw InvalidReportInputException::create(InvalidReportInputException::TITLE_MISSING);
        }
        if (mb_strlen($title) > self::TITLE_MAX_LENGTH) {
            throw InvalidReportInputException::create(InvalidReportInputException::TITLE_TOO_LONG);
        }
        return $title;
    }

    /**
     * @throws InvalidReportInputException
     */
    public static function description(string $description): string
    {
        self::assertValidEncoding($description);
        $description = str_replace(["\r\n", "\r"], "\n", $description);
        $description = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $description);
        $description = trim($description, " \n");
        if (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
            throw InvalidReportInputException::create(InvalidReportInputException::DESCRIPTION_TOO_LONG);
        }
        return $description;
    }

    private static function assertValidEncoding(string $text): void
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw InvalidReportInputException::create(InvalidReportInputException::INVALID_ENCODING);
        }
    }
}
