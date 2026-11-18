<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery\Http;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\TransferStats;
use Priebera\ContextReporter\Delivery\SafeErrorMessage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Outgoing HTTP with a real total deadline and a bounded answer, shared by the
 * webhook, the Context Reporter Hub delivery and the Hub overview.
 *
 *  - One deadline covers connecting, waiting for the headers and reading the
 *    body: Guzzle's "timeout" (curl's total time limit), so an answer that
 *    trickles in byte by byte cannot hold the PHP worker longer. The sink
 *    also stops at the deadline, for handlers without a total limit.
 *  - The body is written into a sink that stops at the limit (the transfer is
 *    aborted there), and it is read completely, in pieces, not with one read:
 *    an answer that arrives in several chunks keeps its reference and link.
 *  - Redirects are not followed; failure messages never contain the URL or
 *    configured secrets.
 *
 * @internal
 */
final readonly class BoundedHttpClient
{
    public const MAX_RESPONSE_BYTES = 65536;
    private const READ_CHUNK = 8192;

    public function __construct(
        private RequestFactory $requestFactory,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param array<string, string> $headers
     * @param list<string> $secrets Values to redact from failure messages
     */
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeoutSeconds,
        array $secrets = [],
        float $connectTimeoutSeconds = 5.0,
        int $maxBytes = self::MAX_RESPONSE_BYTES,
    ): HttpExchange {
        $timeoutSeconds = max(0.1, $timeoutSeconds);
        $deadline = microtime(true) + $timeoutSeconds;
        $exceeded = false;
        $late = false;
        $written = 0;
        $buffer = Utils::streamFor('');
        $sink = FnStream::decorate($buffer, [
            'write' => static function (string $data) use (&$written, &$exceeded, &$late, $maxBytes, $deadline, $buffer): int {
                // Writing less than given aborts the transfer (curl: CURLE_WRITE_ERROR,
                // other handlers: an exception while copying the body)
                if ($written + strlen($data) > $maxBytes) {
                    $exceeded = true;
                    return 0;
                }
                if (microtime(true) > $deadline) {
                    // curl enforces the deadline itself; this also covers handlers without a total limit
                    $late = true;
                    return 0;
                }
                $written += strlen($data);
                return $buffer->write($data);
            },
        ]);
        $status = null;
        $contentType = '';
        // Whether the whole request went out, from the handler's statistics (curl: size_upload); null when unknown
        $sent = null;
        $options = [
            'on_stats' => static function (TransferStats $stats) use (&$sent, $body): void {
                $uploaded = $stats->getHandlerStat('size_upload');
                if (is_int($uploaded) || is_float($uploaded)) {
                    $sent = $uploaded >= strlen((string)$body);
                }
            },
            'headers' => $headers,
            'timeout' => $timeoutSeconds,
            'connect_timeout' => min($connectTimeoutSeconds, $timeoutSeconds),
            'read_timeout' => $timeoutSeconds,
            'allow_redirects' => false,
            'http_errors' => false,
            'sink' => $sink,
            'on_headers' => static function (ResponseInterface $response) use (&$status, &$contentType, &$exceeded, $maxBytes): void {
                $status = $response->getStatusCode();
                $contentType = $response->getHeaderLine('Content-Type');
                $length = $response->getHeaderLine('Content-Length');
                if ($length !== '' && ctype_digit($length) && (int)$length > $maxBytes) {
                    $exceeded = true;
                    throw new \RuntimeException('The answer is larger than allowed.', 1791043301);
                }
            },
        ];
        if ($body !== null) {
            $options['body'] = $body;
        }

        try {
            $response = $this->requestFactory->request($url, $method, $options);
        } catch (\Throwable $exception) {
            if ($status !== null) {
                // The headers arrived, the body did not (completely)
                return HttpExchange::bodyUnusable($status, $contentType, $exceeded ? HttpExchange::PROBLEM_TOO_LARGE : HttpExchange::PROBLEM_INCOMPLETE);
            }
            $this->logger?->notice('Request without an answer: {error}', SafeErrorMessage::logContext($exception, $secrets));
            return HttpExchange::noAnswer(SafeErrorMessage::describeTransportFailure($exception), $sent ?? SafeErrorMessage::mayHaveReachedServer($exception));
        }

        $status = $response->getStatusCode();
        $contentType = $response->getHeaderLine('Content-Type');
        if ($exceeded || $late) {
            return HttpExchange::bodyUnusable($status, $contentType, $exceeded ? HttpExchange::PROBLEM_TOO_LARGE : HttpExchange::PROBLEM_INCOMPLETE);
        }
        // The sink when the handler wrote into it, otherwise (handlers that ignore the sink) the response body
        [$text, $problem] = self::readCompletely($response->getBody(), $maxBytes, $deadline);
        if ($problem !== '') {
            return HttpExchange::bodyUnusable($status, $contentType, $problem);
        }
        return HttpExchange::answered($status, $text, $contentType);
    }

    /**
     * Reads a body to its end, in pieces, within the deadline and the size limit.
     *
     * @return array{string, string} The body and '' or the problem
     */
    public static function readCompletely(StreamInterface $stream, int $maxBytes, float $deadline): array
    {
        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $body = '';
            while (!$stream->eof()) {
                if (microtime(true) > $deadline) {
                    return ['', HttpExchange::PROBLEM_INCOMPLETE];
                }
                $piece = $stream->read(self::READ_CHUNK);
                if ($piece === '') {
                    // A stream that reports no end but has no data: give it until the deadline
                    if (microtime(true) > $deadline) {
                        return ['', HttpExchange::PROBLEM_INCOMPLETE];
                    }
                    usleep(1000);
                    continue;
                }
                $body .= $piece;
                if (strlen($body) > $maxBytes) {
                    return ['', HttpExchange::PROBLEM_TOO_LARGE];
                }
            }
            return [$body, ''];
        } catch (\Throwable) {
            return ['', HttpExchange::PROBLEM_INCOMPLETE];
        }
    }
}
