<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Database;

use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Database\DatabaseSchemaState;
use Priebera\ContextReporter\Domain\Repository\DeliveryAttemptRepository;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class DatabaseSchemaStateTest extends UnitTestCase
{
    #[Test]
    public function aMissingColumnMakesTheSchemaOutdated(): void
    {
        $state = new DatabaseSchemaState($this->createPool(['uid', 'report_uid', 'message']), new NullLogger());

        self::assertFalse($state->isCurrent());
        self::assertSame([DeliveryAttemptRepository::TABLE . '.hub_receipt'], $state->getMissingColumns());
        self::assertFalse($state->hasColumn(DeliveryAttemptRepository::TABLE, 'hub_receipt'));
    }

    #[Test]
    public function columnNamesAreComparedWithoutCaseAndQuotes(): void
    {
        $state = new DatabaseSchemaState($this->createPool(['uid', '`HUB_RECEIPT`']), new NullLogger());

        self::assertTrue($state->isCurrent());
        self::assertTrue($state->hasColumn(DeliveryAttemptRepository::TABLE, 'hub_receipt'));
    }

    /**
     * Columns that cannot be read do not stop deliveries, but are not written either:
     * an insert must not fail after a delivery left the installation.
     */
    #[Test]
    public function unreadableColumnsBlockNothingAndAreNotWritten(): void
    {
        $state = new DatabaseSchemaState($this->createPool(null), new NullLogger());

        self::assertTrue($state->isCurrent());
        self::assertFalse($state->hasColumn(DeliveryAttemptRepository::TABLE, 'hub_receipt'));
    }

    /**
     * @param list<string>|null $columns null: reading the columns fails
     */
    private function createPool(?array $columns): ConnectionPool
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        if ($columns === null) {
            $schemaManager->method('listTableColumns')->willThrowException(new \RuntimeException('no access'));
        } else {
            $schemaManager->method('listTableColumns')->willReturn(array_combine($columns, array_map(fn(string $name): Column => $this->createMock(Column::class), $columns)));
        }
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);
        return $pool;
    }
}
