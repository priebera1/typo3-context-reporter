<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Settings;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Settings\HubConnectionTest;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class HubConnectionTestTest extends UnitTestCase
{
    #[Test]
    public function hubAnswersAreExplained(): void
    {
        $reasons = [];
        foreach ([null, 400, 401, 404, 410, 413, 422, 429, 301, 308, 500, 503] as $code) {
            $reasons[$code ?? 'none'] = HubConnectionTest::failureReason($code);
        }

        self::assertSame([
            'none' => 'unreachable',
            400 => 'rejected',
            401 => 'signature',
            404 => 'unknownUrl',
            410 => 'disconnected',
            413 => 'rejected',
            422 => 'rejected',
            429 => 'rateLimited',
            301 => 'redirect',
            308 => 'redirect',
            500 => 'unavailable',
            503 => 'unavailable',
        ], $reasons);
    }
}
