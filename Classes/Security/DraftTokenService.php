<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Security;

use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Exception\Crypto\InvalidHashStringException;

/**
 * Seals the collected context document when the report dialog opens.
 *
 * The dialog shows this exact document to the reporter. On submit the server
 * only accepts the sealed document back, so what was reviewed is what gets
 * stored and sent, and the browser can neither alter nor inject context data.
 * The token is bound to the backend user and expires.
 *
 * @internal
 */
final readonly class DraftTokenService
{
    public const HMAC_PURPOSE = 'context-reporter-report-draft';
    public const LIFETIME_SECONDS = 21600;
    private const VERSION = 1;

    public function __construct(
        private HashService $hashService,
    ) {}

    /**
     * @param array<string, mixed> $document
     */
    public function issue(array $document, int $backendUserId, ?int $now = null): string
    {
        $now ??= time();
        $claims = [
            'v' => self::VERSION,
            'uid' => $backendUserId,
            'iat' => $now,
            'exp' => $now + self::LIFETIME_SECONDS,
            'doc' => $document,
        ];
        $json = json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        return $this->hashService->appendHmac($encoded, self::HMAC_PURPOSE);
    }

    /**
     * @return array<string, mixed> The document exactly as it was issued
     * @throws InvalidDraftTokenException
     */
    public function verify(string $token, int $backendUserId, ?int $now = null): array
    {
        $now ??= time();
        if ($token === '' || strlen($token) > 1_000_000) {
            throw new InvalidDraftTokenException('The report draft is missing or too large.', 1757930001);
        }
        try {
            $encoded = $this->hashService->validateAndStripHmac($token, self::HMAC_PURPOSE);
        } catch (InvalidHashStringException) {
            throw new InvalidDraftTokenException('The report draft signature is invalid.', 1757930002);
        }
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        $claims = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($claims)
            || ($claims['v'] ?? null) !== self::VERSION
            || !is_int($claims['uid'] ?? null)
            || !is_int($claims['exp'] ?? null)
            || !is_array($claims['doc'] ?? null)
        ) {
            throw new InvalidDraftTokenException('The report draft has an unexpected structure.', 1757930003);
        }
        if ($claims['uid'] !== $backendUserId) {
            throw new InvalidDraftTokenException('The report draft belongs to another backend user.', 1757930004);
        }
        if ($claims['exp'] < $now) {
            throw new InvalidDraftTokenException('The report draft has expired.', 1757930005);
        }
        /** @var array<string, mixed> $document */
        $document = $claims['doc'];
        return $document;
    }
}
