<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Delivery;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\WebhookSettings;
use Priebera\ContextReporter\Delivery\SafeErrorMessage;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SafeErrorMessageTest extends UnitTestCase
{
    #[Test]
    public function urlsAreRemovedBecauseWebhookPathsOftenContainTokens(): void
    {
        $message = SafeErrorMessage::sanitize('cURL error 28: Operation timed out for https://n8n.example.com/webhook/5f1c-secret-path?token=abc');

        self::assertSame('cURL error 28: Operation timed out for [url]', $message);
    }

    #[Test]
    public function configuredSecretsAreRedacted(): void
    {
        $message = SafeErrorMessage::sanitize('Unauthorized, got header Bearer abc123token', ['Bearer abc123token', '']);

        self::assertSame('Unauthorized, got header [redacted]', $message);
    }

    #[Test]
    public function urlEncodedSecretsAreRedacted(): void
    {
        $message = SafeErrorMessage::sanitize(
            'No hook registered for %2Fwebhook%2F5f1c-secret-path, callback=Bearer+abc123token or Bearer%20abc123token',
            ['/webhook/5f1c-secret-path', 'Bearer abc123token'],
        );

        self::assertSame('No hook registered for [redacted], callback=[redacted] or [redacted]', $message);
    }

    #[Test]
    public function veryShortSecretsAreNotUsedForRedactionToAvoidMangledMessages(): void
    {
        self::assertSame('a b c', SafeErrorMessage::sanitize('a b c', ['a']));
    }

    #[Test]
    public function controlCharactersAndWhitespaceAreNormalizedAndLengthIsLimited(): void
    {
        $message = SafeErrorMessage::sanitize("line1\r\n\tline2\x00" . str_repeat('x', 600), [], 20);

        self::assertSame('line1 line2xxxxxxxxx…', $message);
        self::assertSame(21, mb_strlen($message));
    }

    #[Test]
    public function throwableIsDescribedByShortClassNameAndSanitizedMessage(): void
    {
        $message = SafeErrorMessage::fromThrowable(
            new \RuntimeException('Failed to connect to https://user:pass@hooks.example.com/x'),
            ['pass'],
        );

        self::assertSame('RuntimeException: Failed to connect to [url]', $message);
    }

    #[Test]
    public function logContextDescribesTheErrorWithoutTheExceptionObject(): void
    {
        $line = __LINE__ + 1;
        $exception = new \RuntimeException('Connection to smtp://mailer:db-secret-value@mail.example.com failed with db-secret-value ' . str_repeat('x', 400), 1758500001);

        $context = SafeErrorMessage::logContext($exception, ['db-secret-value']);

        self::assertSame(['error', 'exceptionClass', 'exceptionCode', 'location'], array_keys($context));
        self::assertStringStartsWith('RuntimeException: Connection to [url] failed with [redacted] xxx', $context['error']);
        self::assertLessThanOrEqual(301, mb_strlen($context['error']));
        self::assertSame(\RuntimeException::class, $context['exceptionClass']);
        self::assertSame(1758500001, $context['exceptionCode']);
        self::assertSame('SafeErrorMessageTest.php:' . $line, $context['location']);
        self::assertStringNotContainsString('db-secret-value', json_encode($context, JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function untrustedAnswers(): iterable
    {
        yield 'password' => ['Rejected: password=hunter2 for user', 'Rejected: password=[redacted] for user'];
        yield 'token in JSON' => ['{"error":"bad","token":"abc123def456"}', '{"error":"bad","token":"[redacted]"}'];
        yield 'client secret with colon' => ['client_secret: s3cr3t-value', 'client_secret: [redacted]'];
        yield 'secret key value' => ['secret=whsec_LEAKLEAKLEAKLEAKLEAK at receiver', 'secret=[redacted] at receiver'];
        yield 'webhook secret alone' => ['Expected whsec_8Fq2LmZx4RtY7vB1nKc3Wd9P', 'Expected [redacted]'];
        yield 'api key header echo' => ['X-API-Key: 0123456789abcdef', 'X-API-Key: [redacted]'];
        yield 'authorization bearer' => ['Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJlMTIz', 'Authorization: [redacted]'];
        yield 'bearer alone' => ['sent Bearer abcdefghijklmnop', 'sent Bearer [redacted]'];
        yield 'basic auth' => ['Basic dXNlcjpwYXNzd29yZA== is invalid', 'Basic [redacted] is invalid'];
        yield 'GitLab token' => ['token glpat-abcdefghij1234567890 expired', 'token [redacted] expired'];
        yield 'URL with credentials and query' => ['POST https://user:pw@internal.example/x?token=abc failed', 'POST [url] failed'];
        yield 'credentials without scheme' => ['connect admin:geheim@db.internal:5432', 'connect [redacted]@db.internal:5432'];
        yield 'stack trace secret' => ['at /srv/app/secret.js:12 password=hunter2', 'at /srv/app/secret.js:12 password=[redacted]'];
        yield 'prefixed name' => ['db_password=hunter2 rejected', 'db_password=[redacted] rejected'];
        yield 'header with prefix' => ['X-Auth-Token: abcdef123', 'X-Auth-Token: [redacted]'];
        yield 'GitLab header' => ['PRIVATE-TOKEN: abcdefgh12', 'PRIVATE-TOKEN: [redacted]'];
        yield 'camel case name' => ['{"apiToken":"abcdefgh"}', '{"apiToken":"[redacted]"}'];
        yield 'JSON inside JSON' => ['{"error":"{\"token\":\"abcdef123\"}"}', '{"error":"{\"token\":\"[redacted]\"}"}'];
        yield 'escaped quote in value' => ['{"password":"ab\"cdef"}', '{"password":"[redacted]"}'];
        yield 'cookie header' => ['Cookie: a=b; sid=topsecret', 'Cookie: [redacted]'];
    }

    #[Test]
    #[DataProvider('untrustedAnswers')]
    public function credentialsInUntrustedAnswersAreRedacted(string $answer, string $expected): void
    {
        self::assertSame($expected, SafeErrorMessage::sanitize($answer));
    }

    #[Test]
    #[DataProvider('untrustedAnswers')]
    public function sanitizingAStoredMessageAgainChangesNothing(string $answer, string $expected): void
    {
        self::assertSame($expected, SafeErrorMessage::sanitize(SafeErrorMessage::sanitize($answer)));
    }

    #[Test]
    public function ordinaryDiagnosticTextIsKept(): void
    {
        foreach ([
            'HTTP 404: {"error":"unknown_endpoint","message":"This webhook URL is not known to Context Reporter Hub."}',
            'The signature does not match. Copy the current signing secret from Context Reporter Hub.',
            '{"error":"invalid_credentials"}',
            'Token expired, request a new one',
            'Service Unavailable',
            'Rate limit: 60 requests per minute',
            'Error at 10:30@node1',
        ] as $text) {
            self::assertSame($text, SafeErrorMessage::sanitize($text), $text);
        }
    }

    /**
     * The site token of a Hub webhook URL is redacted in the URL and when it
     * appears on its own (echoed by an answer, a proxy or a log line), whether
     * or not it belongs to the configured site. The rest of the message stays
     * useful for troubleshooting.
     */
    #[Test]
    public function hubSiteTokensAreRedactedInUrlsAndOnTheirOwn(): void
    {
        $url = 'https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P';
        $hub = new WebhookSettings(true, $url, 'whsec_8Fq2LmZx4RtY7vB1nKc3Wd9PaQ5sT0uV', '', '', true, 5, false);
        $secrets = $hub->getSecrets();

        self::assertContains('s_8Fq2LmZx4RtY7vB1nKc3Wd9P', $secrets, 'The token on its own is a secret of the setting');
        // In the URL
        self::assertSame('HTTP 404: no site for [url]', SafeErrorMessage::sanitize('HTTP 404: no site for ' . $url, $secrets));
        self::assertSame('POST [redacted] failed', SafeErrorMessage::sanitize('POST /in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P failed', $secrets));
        // On its own: the configured token, and the token of another site
        self::assertSame('HTTP 410: site [redacted] is disconnected', SafeErrorMessage::sanitize('HTTP 410: site s_8Fq2LmZx4RtY7vB1nKc3Wd9P is disconnected', $secrets));
        self::assertSame('{"error":"unknown_endpoint","site":"[redacted]"}', SafeErrorMessage::sanitize('{"error":"unknown_endpoint","site":"s_Zz9Yy8Xx7Ww6Vv5Uu4Tt3Ss2"}'));
        self::assertSame('Path /in/[redacted] is unknown', SafeErrorMessage::sanitize('Path /in/s_Zz9Yy8Xx7Ww6Vv5Uu4Tt3Ss2 is unknown'));
        // What is not a site token stays: the status, the error code, the host, short or embedded s_ words
        foreach ([
            'HTTP 404: unknown_endpoint: The Hub does not know this webhook URL (hub.example.com).',
            'HTTP 401: invalid_signature: stale timestamp',
            'Fields s_title and s_description are too long',
            'Column class_s_8Fq2LmZx4RtY7vB1nKc3Wd9P and s_8Fq2LmZx4RtY7vB1nKc3Wd9P_old are unrelated',
        ] as $text) {
            self::assertSame($text, SafeErrorMessage::sanitize($text), $text);
        }
        self::assertSame('HTTP 404: unknown_endpoint (hub.example.com)', SafeErrorMessage::sanitize('HTTP 404: unknown_endpoint (hub.example.com)', $secrets));
    }

    /**
     * @return iterable<string, array{\Throwable, string}>
     */
    public static function transportFailures(): iterable
    {
        yield 'timeout' => [new \RuntimeException('cURL error 28: Operation timed out after 5002 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://hub.example.com/in/s_x'), 'No answer within the time limit (timeout) (cURL error 28).'];
        yield 'DNS' => [new \RuntimeException('cURL error 6: Could not resolve host: hooks.example.com'), 'The host name could not be resolved (DNS) (cURL error 6).'];
        yield 'refused' => [new \RuntimeException('cURL error 7: Failed to connect to 10.0.0.5 port 443: Connection refused'), 'The server could not be reached (connection refused or blocked) (cURL error 7).'];
        yield 'TLS' => [new \RuntimeException('cURL error 60: SSL certificate problem: self-signed certificate'), 'The secure connection (TLS) failed: certificate or handshake problem (cURL error 60).'];
        yield 'empty reply' => [new \RuntimeException('cURL error 52: Empty reply from server'), 'The server closed the connection without an answer (cURL error 52).'];
        yield 'SMTP refused' => [new \RuntimeException('Connection could not be established with host "127.0.0.1:1": stream_socket_client(): Unable to connect to 127.0.0.1:1 (Connection refused)'), 'The server could not be reached (connection refused or blocked).'];
        yield 'SMTP login' => [new \RuntimeException('Failed to authenticate on SMTP server with username "mailer" using 2 possible authenticators. Authenticator LOGIN returned Expected response code "235" but got code "535", with message "535 5.7.8 Error: authentication failed: UGFzc3dvcmQ6".'), 'The mail server rejected the login (authentication failed).'];
        yield 'unknown' => [new \LogicException('Something odd with password=hunter2'), 'The request could not be completed (network error). See the TYPO3 log for details.'];
    }

    #[Test]
    public function configuredSecretsAreRedactedWhenAnAnswerEscapesThemForJson(): void
    {
        self::assertSame('{"echo":"[redacted]"}', SafeErrorMessage::sanitize('{"echo":"abc\/def+ghi"}', ['abc/def+ghi']));
    }

    #[Test]
    public function longAnswersAreScannedWithBoundedCost(): void
    {
        foreach (['%', '+', 'a%', 'password=', ':'] as $chunk) {
            $start = hrtime(true);
            SafeErrorMessage::sanitize(str_repeat($chunk, intdiv(65536, strlen($chunk))) . 'x:y@host');
            self::assertLessThan(0.5, (hrtime(true) - $start) / 1e9, $chunk);
        }
    }

    #[Test]
    public function theCurlErrorNumberDecidesNotTheHostName(): void
    {
        self::assertSame(
            'The server could not be reached (connection refused or blocked) (cURL error 7).',
            SafeErrorMessage::describeTransportFailure(new \RuntimeException('cURL error 7: Failed to connect to ssl-timeout.example.com port 443')),
        );
    }

    #[Test]
    #[DataProvider('transportFailures')]
    public function transportFailuresAreDescribedWithoutTheLibraryText(\Throwable $failure, string $expected): void
    {
        $description = SafeErrorMessage::describeTransportFailure($failure);
        self::assertSame($expected, $description);
        foreach (['hub.example.com', 's_x', '10.0.0.5', 'hunter2', 'mailer', 'UGFzc3dvcmQ6'] as $detail) {
            self::assertStringNotContainsString($detail, $description);
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function failuresWithoutTransferStatistics(): iterable
    {
        yield 'operation timeout after connecting' => ['cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received', true];
        yield 'connect timeout' => ['cURL error 28: Connection timed out after 5001 milliseconds', false];
        yield 'connect failure within the time limit' => ['cURL error 28: Failed to connect to hub.example.com port 443 after 5001 ms: Timeout was reached', false];
        yield 'resolving timeout' => ['cURL error 28: Resolving timed out after 5000 milliseconds', false];
        yield 'empty reply' => ['cURL error 52: Empty reply from server', true];
        yield 'reset while receiving' => ['cURL error 56: Recv failure: Connection reset by peer', true];
        yield 'refused' => ['cURL error 7: Failed to connect to hub.example.com port 443: Connection refused', false];
        yield 'dns' => ['cURL error 6: Could not resolve host: hub.example.com', false];
        yield 'tls' => ['cURL error 60: SSL certificate problem: unable to get local issuer certificate', false];
        yield 'stream handler timeout' => ['Connection to the server timed out while reading', true];
    }

    /**
     * Only used when the handler gave no transfer statistics (BoundedHttpClient prefers them).
     */
    #[Test]
    #[DataProvider('failuresWithoutTransferStatistics')]
    public function aFailureMayHaveReachedTheServerOnlyAfterTheConnection(string $message, bool $expected): void
    {
        self::assertSame($expected, SafeErrorMessage::mayHaveReachedServer(new \RuntimeException($message)));
    }
}
