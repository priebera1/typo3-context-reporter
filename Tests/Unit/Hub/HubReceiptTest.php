<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Hub;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Hub\HubReceipt;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class HubReceiptTest extends UnitTestCase
{
    #[Test]
    public function readsTheScreenshotFactsAndTheStorageState(): void
    {
        $receipt = HubReceipt::fromAnswer([
            'reference' => 'LW-152',
            'screenshots' => ['received' => 2, 'stored' => 1, 'omitted' => 1, 'reasons' => ['storage_full']],
            'storage' => 'ok',
        ]);

        self::assertNotNull($receipt);
        self::assertSame(2, $receipt->screenshotsReceived);
        self::assertSame(1, $receipt->screenshotsStored);
        self::assertSame(1, $receipt->screenshotsOmitted);
        self::assertSame(['storage_full'], $receipt->reasons);
        self::assertSame('ok', $receipt->storage);
        self::assertTrue($receipt->hasOmittedScreenshots());
        self::assertSame('storage_full', $receipt->getPrimaryReason());
        self::assertFalse($receipt->isStorageCritical());
    }

    #[Test]
    public function anAnswerWithoutTheOptionalKeysHasNoReceipt(): void
    {
        self::assertNull(HubReceipt::fromAnswer([]));
        self::assertNull(HubReceipt::fromAnswer(['reference' => 'LW-152', 'url' => 'https://hub.example.com/app/reports/LW-152']));
    }

    #[Test]
    public function allScreenshotsStoredIsNoNotice(): void
    {
        $receipt = HubReceipt::fromAnswer(['screenshots' => ['received' => 1, 'stored' => 1, 'omitted' => 0, 'reasons' => []]]);

        self::assertNotNull($receipt);
        self::assertFalse($receipt->hasOmittedScreenshots());
        self::assertSame(0, $receipt->getOmittedScreenshots());
        self::assertNull($receipt->storage);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedScreenshots(): iterable
    {
        yield 'string' => ['storage_full'];
        yield 'number' => [1];
        yield 'list' => [[2, 1, 1]];
        yield 'null' => [null];
        yield 'empty object' => [[]];
        yield 'wrong value types' => [['received' => '2', 'stored' => 1.0, 'omitted' => true, 'reasons' => 'storage_full']];
        yield 'negative counts' => [['received' => -1, 'stored' => -5, 'omitted' => -1]];
        yield 'reasons as object' => [['reasons' => ['code' => 'storage_full']]];
    }

    #[Test]
    #[DataProvider('malformedScreenshots')]
    public function malformedScreenshotFactsAreIgnored(mixed $screenshots): void
    {
        self::assertNull(HubReceipt::fromAnswer(['screenshots' => $screenshots]));
        $withStorage = HubReceipt::fromAnswer(['screenshots' => $screenshots, 'storage' => 'critical']);
        self::assertNotNull($withStorage);
        self::assertFalse($withStorage->hasOmittedScreenshots());
        self::assertSame('critical', $withStorage->storage);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unknownStorageStates(): iterable
    {
        yield 'unknown code' => ['almost_full'];
        yield 'other case' => ['FULL'];
        yield 'number' => [95];
        yield 'object' => [['state' => 'full']];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('unknownStorageStates')]
    public function unknownStorageStatesAreIgnored(mixed $storage): void
    {
        self::assertNull(HubReceipt::fromAnswer(['storage' => $storage]));
    }

    #[Test]
    public function knownStorageStates(): void
    {
        foreach (['ok' => false, 'nearly_full' => false, 'critical' => true, 'full' => true] as $state => $critical) {
            $receipt = HubReceipt::fromAnswer(['storage' => $state]);
            self::assertNotNull($receipt);
            self::assertSame($state, $receipt->storage);
            self::assertSame($critical, $receipt->isStorageCritical(), $state);
        }
    }

    #[Test]
    public function unknownReasonsAreTreatedGenerically(): void
    {
        $receipt = HubReceipt::fromAnswer(['screenshots' => [
            'omitted' => 1,
            'reasons' => ['quota_exceeded_v2', 42, null, ['storage_full'], '<script>', 'other'],
        ]]);

        self::assertNotNull($receipt);
        self::assertSame(['other'], $receipt->reasons);
        self::assertSame('other', $receipt->getPrimaryReason());
    }

    #[Test]
    public function theFirstKnownReasonIsNamed(): void
    {
        $receipt = HubReceipt::fromAnswer(['screenshots' => ['omitted' => 2, 'reasons' => ['future_reason', 'too_large', 'storage_full', 'too_large']]]);

        self::assertNotNull($receipt);
        self::assertSame(['other', 'too_large', 'storage_full'], $receipt->reasons);
        self::assertSame('too_large', $receipt->getPrimaryReason());
    }

    #[Test]
    public function withoutReasonsTheReasonIsUnknown(): void
    {
        $receipt = HubReceipt::fromAnswer(['screenshots' => ['received' => 1, 'stored' => 0, 'omitted' => 1]]);

        self::assertNotNull($receipt);
        self::assertSame([], $receipt->reasons);
        self::assertSame('other', $receipt->getPrimaryReason());
    }

    #[Test]
    public function hugeValuesAreClamped(): void
    {
        $receipt = HubReceipt::fromAnswer(['screenshots' => [
            'received' => PHP_INT_MAX,
            'stored' => 0,
            'omitted' => 10 ** 12,
            'reasons' => array_merge(array_fill(0, 1000, 'unknown'), ['storage_full']),
        ]]);

        self::assertNotNull($receipt);
        self::assertSame(HubReceipt::MAX_COUNT, $receipt->screenshotsReceived);
        self::assertSame(HubReceipt::MAX_COUNT, $receipt->screenshotsOmitted);
        // Only the first entries are read
        self::assertSame(['other'], $receipt->reasons);
    }

    #[Test]
    public function withoutOmittedTheDifferenceOfReceivedAndStoredCounts(): void
    {
        $receipt = HubReceipt::fromAnswer(['screenshots' => ['received' => 2, 'stored' => 1, 'omitted' => 'one']]);
        self::assertNotNull($receipt);
        self::assertNull($receipt->screenshotsOmitted);
        self::assertSame(1, $receipt->getOmittedScreenshots());

        $more = HubReceipt::fromAnswer(['screenshots' => ['received' => 1, 'stored' => 3]]);
        self::assertNotNull($more);
        self::assertSame(0, $more->getOmittedScreenshots());
    }

    #[Test]
    public function survivesStorageThroughTheSameRules(): void
    {
        $receipt = HubReceipt::fromAnswer([
            'screenshots' => ['received' => 1, 'stored' => 0, 'omitted' => 1, 'reasons' => ['storage_full', 'something_new']],
            'storage' => 'full',
        ]);
        self::assertNotNull($receipt);

        $json = $receipt->toJson();
        self::assertSame('{"screenshots":{"received":1,"stored":0,"omitted":1,"reasons":["storage_full","other"]},"storage":"full"}', $json);
        self::assertEquals($receipt, HubReceipt::fromJson($json));

        self::assertNull(HubReceipt::fromJson(''));
        self::assertNull(HubReceipt::fromJson('not json'));
        self::assertNull(HubReceipt::fromJson('"storage"'));
        self::assertNull(HubReceipt::fromJson('{"storage":"' . str_repeat('x', 5000) . '"}'));
    }
}
