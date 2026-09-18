<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery\Webhook;

/**
 * HMAC-SHA256 signature over "<unix timestamp>.<raw request body>", sent as
 *
 *     X-Context-Reporter-Signature: t=<timestamp>,v1=<hex digest>
 *
 * Receivers recompute the digest with the shared secret, compare it in
 * constant time and reject old timestamps to prevent replays.
 */
final class WebhookSignature
{
    public const HEADER = 'X-Context-Reporter-Signature';

    public static function create(string $secret, int $timestamp, string $body): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public static function verify(string $header, string $secret, string $body, int $toleranceSeconds = 300, ?int $now = null): bool
    {
        $now ??= time();
        if (!preg_match('/^t=(\d{1,12}),v1=([a-f0-9]{64})$/D', $header, $matches)) {
            return false;
        }
        $timestamp = (int)$matches[1];
        if (abs($now - $timestamp) > $toleranceSeconds) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $secret), $matches[2]);
    }
}
