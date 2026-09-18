<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Report;

/**
 * @internal
 */
final class InvalidReportInputException extends \RuntimeException
{
    public const TITLE_MISSING = 'titleMissing';
    public const TITLE_TOO_LONG = 'titleTooLong';
    public const DESCRIPTION_TOO_LONG = 'descriptionTooLong';
    public const INVALID_ENCODING = 'invalidEncoding';

    public static function create(string $reason): self
    {
        return new self($reason, 1757930601);
    }

    public function getReason(): string
    {
        return $this->getMessage();
    }
}
