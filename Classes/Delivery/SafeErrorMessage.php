<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery;

/**
 * Produces delivery error texts that are safe to store in the audit trail and
 * to show in the backend: configured secrets are redacted, URLs are removed
 * (webhook URLs frequently carry tokens) and the text is bounded.
 *
 * @internal
 */
final class SafeErrorMessage
{
    private const MIN_SECRET_LENGTH = 4;

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
        $message = (string)preg_replace('~[a-z][a-z0-9+.\-]*://[^\s"\'<>]+~i', '[url]', $message);
        foreach (self::getVariants($secrets) as $secret) {
            $message = str_replace($secret, '[redacted]', $message);
        }
        $message = str_replace(["\r", "\n", "\t"], ' ', $message);
        $message = (string)preg_replace('/[\x00-\x1F\x7F]/', '', $message);
        $message = trim((string)preg_replace('/ {2,}/', ' ', $message));
        if (mb_strlen($message) > $maxLength) {
            $message = mb_substr($message, 0, $maxLength) . '…';
        }
        return $message;
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
            foreach ([$secret, rawurlencode($secret), urlencode($secret)] as $variant) {
                $variants[$variant] = strlen($variant);
            }
        }
        arsort($variants);
        return array_map(strval(...), array_keys($variants));
    }
}
