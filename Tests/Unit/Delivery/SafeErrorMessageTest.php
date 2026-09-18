<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Delivery;

use PHPUnit\Framework\Attributes\Test;
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
}
