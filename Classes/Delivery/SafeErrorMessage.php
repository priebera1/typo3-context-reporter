<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery;

/**
 * Produces delivery error texts that are safe to store in the audit trail and
 * to show in the backend: configured secrets are redacted, URLs are removed
 * (webhook URLs frequently carry tokens), values that look like credentials
 * are redacted (receivers are not trusted: an answer may echo anything) and
 * the text is bounded.
 *
 * @internal
 */
final class SafeErrorMessage
{
    private const MIN_SECRET_LENGTH = 4;

    /**
     * Names of credential fields: their value is redacted in "name=value",
     * "name: value" and JSON ("name":"value").
     */
    private const SECRET_KEYS = 'password|passwd|pwd|pass|passphrase|secret|secret[_-]?key|token|api[_-]?key|apikey|private[_-]?key|authorization|credentials?|session[_-]?id|sessionid';

    /** Answers are cut to this many characters before they are searched (bounded cost on 64 KB bodies). */
    private const MAX_SCANNED_LENGTH = 8192;

    /**
     * Credentials that are recognizable by themselves: webhook signing secrets,
     * common API key and token prefixes, JSON web tokens, and site tokens of
     * Context Reporter Hub webhook URLs (s_ and 20 to 40 letters or digits, as
     * a word of its own).
     */
    private const SECRET_VALUES = '~(?<![A-Za-z0-9])(?:whsec_|sk_(?:live|test)_|rk_(?:live|test)_|ghp_|gho_|ghu_|ghs_|ghr_|github_pat_|glpat-|xox[abprs]-|AKIA|ASIA)[A-Za-z0-9_\-]{6,}|(?<![A-Za-z0-9])eyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{6,}|(?<![A-Za-z0-9_\-])s_[A-Za-z0-9]{20,40}(?![A-Za-z0-9_\-])~';

    /**
     * @param list<string> $secrets
     */
    public static function fromThrowable(\Throwable $throwable, array $secrets = [], int $maxLength = 500): string
    {
        $className = (new \ReflectionClass($throwable))->getShortName();
        return self::sanitize($className . ': ' . $throwable->getMessage(), $secrets, $maxLength);
    }

    /**
     * @param list<string> $secrets
     */
    public static function sanitize(string $message, array $secrets = [], int $maxLength = 500): string
    {
        $message = mb_scrub($message, 'UTF-8');
        $scanned = max(self::MAX_SCANNED_LENGTH, $maxLength * 2);
        if (mb_strlen($message) > $scanned) {
            $message = mb_substr($message, 0, $scanned);
        }
        $message = (string)preg_replace('~[a-z][a-z0-9+.\-]*://[^\s"\'<>]+~i', '[url]', $message);
        foreach (self::getVariants($secrets) as $secret) {
            $message = str_replace($secret, '[redacted]', $message);
        }
        $message = self::redactCredentials($message);
        $message = str_replace(["\r", "\n", "\t"], ' ', $message);
        $message = (string)preg_replace('/[\x00-\x1F\x7F]/', '', $message);
        $message = trim((string)preg_replace('/ {2,}/', ' ', $message));
        if (mb_strlen($message) > $maxLength) {
            $message = mb_substr($message, 0, $maxLength) . '…';
        }
        return $message;
    }

    /**
     * Values that look like credentials, whoever wrote them: an endpoint may
     * echo a token, a password or a signing secret in its answer.
     */
    public static function redactCredentials(string $message): string
    {
        // name=value, name: value, "name": "value", \"name\":\"value\" (JSON inside JSON), "Authorization: Bearer …";
        // names may carry a prefix (db_password, X-Auth-Token, apiToken). Quotes are kept, the value is replaced.
        $message = (string)preg_replace_callback(
            '~(?<![A-Za-z0-9_.\-])(\\\\?["\']|)([A-Za-z0-9_.\-]{0,64}?(?:' . self::SECRET_KEYS . '))\1(\s*[:=]\s*)(?!(?:\\\\?["\'])?\[redacted\])'
                . '((?:Bearer|Basic|Digest|Token)\s+[^\s"\',;&}\])\\\\]{1,512}|\\\\"(?:(?!\\\\").){0,512}\\\\"|"(?:[^"\\\\]|\\\\.){0,512}"|\'[^\']{0,512}\'|[^\s"\',;&}\])\\\\]{1,512})~i',
            static function (array $match): string {
                $quote = '';
                if (str_starts_with($match[4], '\\"')) {
                    $quote = '\\"';
                } elseif (in_array($match[4][0] ?? '', ['"', "'"], true)) {
                    $quote = $match[4][0];
                }
                return $match[1] . $match[2] . $match[1] . $match[3] . $quote . '[redacted]' . $quote;
            },
            $message,
        );
        // Cookies: the whole header value (several name=value pairs)
        $message = (string)preg_replace('~\b((?:set-)?cookie)(\s*:\s*)[^\r\n"]{1,2048}~i', '$1$2[redacted]', $message);
        // Authorization schemes: "Bearer abc…", "Basic dXNlcjpw…"
        $message = (string)preg_replace('#\b(Bearer|Basic|Digest|Token)\s+[A-Za-z0-9._~+/=\-]{8,512}#i', '$1 [redacted]', $message);
        $message = (string)preg_replace(self::SECRET_VALUES, '[redacted]', $message);
        // user:password@host without a scheme (URLs with a scheme are removed before); the user name has a letter
        return (string)preg_replace(
            '~(?<![A-Za-z0-9._%+\-])(?=[A-Za-z0-9._%+\-]{0,128}[A-Za-z])[A-Za-z0-9._%+\-]{1,128}:[^\s:@/]{1,256}@(?=[A-Za-z0-9\[])~',
            '[redacted]@',
            $message,
        );
    }

    /**
     * A failed connection in words for the delivery history: what happened,
     * not the text of the HTTP or mail library (which names hosts, internal
     * functions and sometimes more). The original goes to the TYPO3 log.
     */
    public static function describeTransportFailure(\Throwable $throwable): string
    {
        $text = $throwable->getMessage();
        $curl = preg_match('/cURL error (\d+)/', $text, $matches) === 1 ? (int)$matches[1] : 0;
        $class = (new \ReflectionClass($throwable))->getShortName();
        $code = $curl > 0 ? ' (cURL error ' . $curl . ')' : '';
        if ($curl > 0) {
            // The number is reliable, the text names hosts that could contain "ssl" or "timeout"
            return match (true) {
                $curl === 28 => 'No answer within the time limit (timeout)' . $code . '.',
                in_array($curl, [5, 6], true) => 'The host name could not be resolved (DNS)' . $code . '.',
                in_array($curl, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 83, 90, 91], true) => 'The secure connection (TLS) failed: certificate or handshake problem' . $code . '.',
                $curl === 7 => 'The server could not be reached (connection refused or blocked)' . $code . '.',
                in_array($curl, [52, 55, 56], true) => 'The server closed the connection without an answer' . $code . '.',
                default => 'The request could not be completed (network error)' . $code . '. See the TYPO3 log for details.',
            };
        }
        return match (true) {
            stripos($text, 'timed out') !== false || stripos($class, 'Timeout') !== false
                => 'No answer within the time limit (timeout).',
            stripos($text, 'resolve host') !== false || stripos($text, 'getaddrinfo') !== false
                => 'The host name could not be resolved (DNS).',
            stripos($text, 'SSL') !== false || stripos($text, 'certificate') !== false
                => 'The secure connection (TLS) failed: certificate or handshake problem.',
            stripos($text, 'Connection refused') !== false || stripos($text, 'could not be established') !== false || stripos($text, 'Unable to connect') !== false
                => 'The server could not be reached (connection refused or blocked).',
            stripos($text, 'Empty reply') !== false || stripos($text, 'reset by peer') !== false
                => 'The server closed the connection without an answer.',
            preg_match('/\b(535|534|530)\b|authenticat/i', $text) === 1
                => 'The mail server rejected the login (authentication failed).',
            preg_match('/Expected response code (\d{3})/', $text, $smtp) === 1
                => 'The mail server did not accept the email (SMTP ' . $smtp[1] . ').',
            stripos($class, 'Transport') !== false
                => 'The mail transport failed. See the TYPO3 log for details.',
            default => 'The request could not be completed (network error). See the TYPO3 log for details.',
        };
    }

    /**
     * Whether a request that got no answer may still have been received, from
     * the error alone (BoundedHttpClient prefers the transfer statistics): the
     * answer did not arrive in time or the connection was closed while waiting
     * for it. Failures before the request could be sent (DNS, connection
     * refused or timed out, TLS) are not.
     */
    public static function mayHaveReachedServer(\Throwable $throwable): bool
    {
        $text = $throwable->getMessage();
        if (stripos($text, 'Connection timed out') !== false || stripos($text, 'Failed to connect') !== false || stripos($text, 'Resolving timed out') !== false) {
            return false;
        }
        if (preg_match('/cURL error (\d+)/', $text, $matches) === 1) {
            return in_array((int)$matches[1], [28, 52, 56], true);
        }
        return stripos($text, 'timed out') !== false
            || stripos((new \ReflectionClass($throwable))->getShortName(), 'Timeout') !== false
            || stripos($text, 'Empty reply') !== false
            || stripos($text, 'reset by peer') !== false;
    }

    /**
     * Log data for an unexpected exception: class, code, a sanitized and
     * bounded message and the throwing location. The exception object is
     * left out on purpose, because its trace can contain argument values.
     *
     * @param list<string> $secrets
     * @return array{error: string, exceptionClass: class-string, exceptionCode: int|string, location: string}
     */
    public static function logContext(\Throwable $throwable, array $secrets = []): array
    {
        return [
            'error' => self::fromThrowable($throwable, $secrets, 300),
            'exceptionClass' => $throwable::class,
            'exceptionCode' => $throwable->getCode(),
            'location' => basename($throwable->getFile()) . ':' . $throwable->getLine(),
        ];
    }

    /**
     * @param list<string> $secrets
     */
    public static function containsSecret(string $value, array $secrets): bool
    {
        foreach (self::getVariants($secrets) as $secret) {
            if (str_contains($value, $secret)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The secrets as given and URL-encoded, longest first; very short values
     * are ignored because they would mangle ordinary text.
     *
     * @param list<string> $secrets
     * @return list<string>
     */
    private static function getVariants(array $secrets): array
    {
        $variants = [];
        foreach ($secrets as $secret) {
            if (mb_strlen($secret) < self::MIN_SECRET_LENGTH) {
                continue;
            }
            $json = [substr((string)json_encode($secret), 1, -1), substr((string)json_encode($secret, JSON_UNESCAPED_SLASHES), 1, -1)];
            foreach ([$secret, rawurlencode($secret), urlencode($secret), ...$json] as $variant) {
                if ($variant === '') {
                    continue;
                }
                $variants[$variant] = strlen($variant);
            }
        }
        arsort($variants);
        return array_map(strval(...), array_keys($variants));
    }
}
