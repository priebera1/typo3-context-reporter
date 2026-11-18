<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\UpdateNotice;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Tests\Unit\Fixtures\BackendUserStub;
use Priebera\ContextReporter\UpdateNotice\SemanticVersion;
use Priebera\ContextReporter\UpdateNotice\UpdateNoticeDismissalStore;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class UpdateNoticeDismissalStoreTest extends UnitTestCase
{
    private static function version(string $value): SemanticVersion
    {
        $version = SemanticVersion::tryParse($value);
        self::assertNotNull($version);
        return $version;
    }

    #[Test]
    public function dismissingHidesExactlyThatReleaseForThatUser(): void
    {
        $store = new UpdateNoticeDismissalStore();
        $admin = BackendUserStub::create(1, true);
        $otherAdmin = BackendUserStub::create(2, true);
        $admin->uc = ['other' => 'kept', UpdateNoticeDismissalStore::UC_KEY => ['unrelated' => 1]];

        $store->dismiss($admin, self::version('0.5.0'));

        self::assertSame(1, $admin->ucWrites);
        self::assertTrue($store->isDismissed($admin, self::version('0.5.0')));
        self::assertFalse($store->isDismissed($admin, self::version('0.5.1')));
        self::assertFalse($store->isDismissed($admin, self::version('0.6.0')));
        self::assertFalse($store->isDismissed($otherAdmin, self::version('0.5.0')));
        self::assertSame('kept', $admin->uc['other']);
        self::assertSame(1, $admin->uc[UpdateNoticeDismissalStore::UC_KEY]['unrelated']);
    }

    #[Test]
    public function keepsTheLastTenVersionsWithoutDuplicates(): void
    {
        $store = new UpdateNoticeDismissalStore();
        $admin = BackendUserStub::create(1, true);
        for ($minor = 1; $minor <= 12; $minor++) {
            $store->dismiss($admin, self::version('0.' . $minor . '.0'));
        }
        $store->dismiss($admin, self::version('0.12.0'));

        $stored = $admin->uc[UpdateNoticeDismissalStore::UC_KEY][UpdateNoticeDismissalStore::UC_FIELD];
        self::assertCount(10, $stored);
        self::assertSame('0.3.0', $stored[0]);
        self::assertSame('0.12.0', $stored[9]);
        self::assertFalse($store->isDismissed($admin, self::version('0.1.0')));
    }

    #[Test]
    public function brokenStoredValuesAreIgnored(): void
    {
        $store = new UpdateNoticeDismissalStore();
        $admin = BackendUserStub::create(1, true);
        foreach (['string', ['dismissedUpdateVersions' => 'x'], ['dismissedUpdateVersions' => [5, null, 'dev-main', '0.5.0']]] as $value) {
            $admin->uc = [UpdateNoticeDismissalStore::UC_KEY => $value];
            self::assertSame(is_array($value) && is_array($value['dismissedUpdateVersions']), $store->isDismissed($admin, self::version('0.5.0')));
        }
        $admin->uc = [UpdateNoticeDismissalStore::UC_KEY => 'string'];
        $store->dismiss($admin, self::version('0.5.0'));
        self::assertTrue($store->isDismissed($admin, self::version('0.5.0')));
    }
}
