<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery\Http;

/**
 * What one outgoing request brought back: the status and the complete body
 * (bounded), or why the body could not be used.
 *
 *  - No status: nothing came back (not reachable, refused, or no answer within
 *    the deadline); `message` says why, without secrets. `mayHaveArrived` is
 *    true when the request may have reached the receiver before the answer
 *    was lost (a timeout, a connection closed while waiting for the answer).
 *  - A status and a problem: the answer's headers arrived, but its body did
 *    not arrive completely: larger than allowed ("tooLarge") or not within the
 *    total deadline ("incomplete"). The body is empty then.
 *
 * @internal
 */
final readonly class HttpExchange
{
    public const PROBLEM_TOO_LARGE = 'tooLarge';
    public const PROBLEM_INCOMPLETE = 'incomplete';

    private function __construct(
        public ?int $status,
        public string $body,
        public string $contentType,
        public string $problem,
        public string $message,
        public bool $mayHaveArrived = false,
    ) {}

    public static function answered(int $status, string $body, string $contentType): self
    {
        return new self($status, $body, $contentType, '', '');
    }

    public static function bodyUnusable(int $status, string $contentType, string $problem): self
    {
        return new self($status, '', $contentType, $problem, '');
    }

    public static function noAnswer(string $message, bool $mayHaveArrived = false): self
    {
        return new self(null, '', '', '', $message, $mayHaveArrived);
    }

    public function hasStatus(): bool
    {
        return $this->status !== null;
    }

    public function isSuccessfulStatus(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }

    public function isBodyComplete(): bool
    {
        return $this->status !== null && $this->problem === '';
    }
}
