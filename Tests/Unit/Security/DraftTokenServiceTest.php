<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Security\DraftTokenService;
use Priebera\ContextReporter\Security\InvalidDraftTokenException;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class DraftTokenServiceTest extends UnitTestCase
{
    private const NOW = 1_780_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-encryption-key-with-enough-entropy';
    }

    #[Test]
    public function issuedTokenReturnsTheExactDocumentForTheSameUser(): void
    {
        $service = new DraftTokenService(new HashService());
        $document = ['subject' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 12], 'summary' => 'Überschrift "Hero" – ok'];

        $token = $service->issue($document, 7, self::NOW);

        self::assertSame($document, $service->verify($token, 7, self::NOW + 60));
    }

    #[Test]
    public function tokenOfAnotherBackendUserIsRejected(): void
    {
        $service = new DraftTokenService(new HashService());
        $token = $service->issue(['summary' => 'x'], 7, self::NOW);

        $this->expectException(InvalidDraftTokenException::class);
        $service->verify($token, 8, self::NOW);
    }

    #[Test]
    public function expiredTokenIsRejected(): void
    {
        $service = new DraftTokenService(new HashService());
        $token = $service->issue(['summary' => 'x'], 7, self::NOW);

        $this->expectException(InvalidDraftTokenException::class);
        $service->verify($token, 7, self::NOW + DraftTokenService::LIFETIME_SECONDS + 1);
    }

    #[Test]
    public function modifiedDocumentIsRejected(): void
    {
        $service = new DraftTokenService(new HashService());
        $token = $service->issue(['summary' => 'original'], 7, self::NOW);
        $signature = substr($token, -40);
        $payload = substr($token, 0, -40);
        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
        self::assertIsString($decoded);
        $forged = rtrim(strtr(base64_encode(str_replace('original', 'modified', $decoded)), '+/', '-_'), '=');

        $this->expectException(InvalidDraftTokenException::class);
        $service->verify($forged . $signature, 7, self::NOW);
    }

    #[Test]
    public function modifiedSignatureIsRejected(): void
    {
        $service = new DraftTokenService(new HashService());
        $token = $service->issue(['summary' => 'x'], 7, self::NOW);
        $lastCharacter = substr($token, -1);
        $tampered = substr($token, 0, -1) . ($lastCharacter === 'a' ? 'b' : 'a');

        $this->expectException(InvalidDraftTokenException::class);
        $service->verify($tampered, 7, self::NOW);
    }

    #[Test]
    public function garbageIsRejected(): void
    {
        $this->expectException(InvalidDraftTokenException::class);
        (new DraftTokenService(new HashService()))->verify('not-a-token', 7, self::NOW);
    }

    #[Test]
    public function correctlySignedTokenWithUnexpectedStructureIsRejected(): void
    {
        $hashService = new HashService();
        $claims = rtrim(strtr(base64_encode((string)json_encode(['v' => 99, 'uid' => 7, 'exp' => self::NOW + 100, 'doc' => []])), '+/', '-_'), '=');
        $token = $hashService->appendHmac($claims, DraftTokenService::HMAC_PURPOSE);

        $this->expectException(InvalidDraftTokenException::class);
        (new DraftTokenService($hashService))->verify($token, 7, self::NOW);
    }

    #[Test]
    public function tokenSignedForAnotherPurposeIsRejected(): void
    {
        $hashService = new HashService();
        $claims = rtrim(strtr(base64_encode((string)json_encode(['v' => 1, 'uid' => 7, 'iat' => self::NOW, 'exp' => self::NOW + 100, 'doc' => []])), '+/', '-_'), '=');
        $token = $hashService->appendHmac($claims, 'some-other-purpose');

        $this->expectException(InvalidDraftTokenException::class);
        (new DraftTokenService($hashService))->verify($token, 7, self::NOW);
    }
}
