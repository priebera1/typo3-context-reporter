<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Report;

/**
 * @internal
 */
final class InvalidScreenshotException extends \RuntimeException
{
    public const EMPTY = 'empty';
    public const TOO_LARGE = 'tooLarge';
    public const FORMAT = 'format';
    public const DIMENSIONS = 'dimensions';

    private string $reason = self::FORMAT;

    public static function create(string $reason, string $message): self
    {
        $exception = new self($message, 1757930101);
        $exception->reason = $reason;
        return $exception;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
