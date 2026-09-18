<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Delivery\Webhook;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Delivery\Webhook\WebhookSignature;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class WebhookSignatureTest extends UnitTestCase
{
    #[Test]
    public function signatureCoversTimestampAndRawBody(): void
    {
        $body = '{"event":"report.created"}';

        $header = WebhookSignature::create('s3cr3t', 1_700_000_000, $body);

        self::assertSame('t=1700000000,v1=' . hash_hmac('sha256', '1700000000.' . $body, 's3cr3t'), $header);
    }

    #[Test]
    public function validSignatureIsVerified(): void
    {
        $header = WebhookSignature::create('s3cr3t', 1_700_000_000, 'body');

        self::assertTrue(WebhookSignature::verify($header, 's3cr3t', 'body', 300, 1_700_000_100));
    }

    #[Test]
    public function modifiedBodyWrongSecretAndOldTimestampsFailVerification(): void
    {
        $header = WebhookSignature::create('s3cr3t', 1_700_000_000, 'body');

        self::assertFalse(WebhookSignature::verify($header, 's3cr3t', 'body!', 300, 1_700_000_000));
        self::assertFalse(WebhookSignature::verify($header, 'other', 'body', 300, 1_700_000_000));
        self::assertFalse(WebhookSignature::verify($header, 's3cr3t', 'body', 300, 1_700_000_301));
        self::assertFalse(WebhookSignature::verify('v1=abc', 's3cr3t', 'body', 300, 1_700_000_000));
        self::assertFalse(WebhookSignature::verify('', 's3cr3t', 'body', 300, 1_700_000_000));
    }
}
