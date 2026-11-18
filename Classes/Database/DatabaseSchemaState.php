<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Database;

use Priebera\ContextReporter\Domain\Repository\DeliveryAttemptRepository;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Whether the database tables have the columns this version writes. After an
 * update of the extension, TYPO3 adds them with "Analyze Database Structure"
 * (Admin Tools › Maintenance) or `vendor/bin/typo3 extension:setup`; until
 * then nothing is delivered that could not be recorded (the delivery history
 * is the only proof of what left the installation).
 *
 * @internal
 */
final class DatabaseSchemaState
{
    /**
     * Columns added after a public release, by table. Columns of the first
     * release are not listed: without them the extension is not installed.
     */
    public const REQUIRED_COLUMNS = [
        // 0.4.0: the acknowledgement of Context Reporter Hub
        DeliveryAttemptRepository::TABLE => ['hub_receipt'],
    ];

    /** @var array<string, list<string>>|null missing column names by table */
    private ?array $missing = null;

    /** @var list<string> tables whose columns could not be read */
    private array $unknown = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly LoggerInterface $logger,
    ) {}

    public function isCurrent(): bool
    {
        return $this->getMissingColumns() === [];
    }

    /**
     * False for a missing column and for a table whose columns could not be
     * read: writing leaves such a column out rather than risking an error
     * after a delivery.
     */
    public function hasColumn(string $table, string $column): bool
    {
        $missing = $this->getMissing();
        return !in_array($table, $this->unknown, true) && !in_array($column, $missing[$table] ?? [], true);
    }

    /**
     * @return list<string> "table.column" of every missing column
     */
    public function getMissingColumns(): array
    {
        $columns = [];
        foreach ($this->getMissing() as $table => $missing) {
            foreach ($missing as $column) {
                $columns[] = $table . '.' . $column;
            }
        }
        return $columns;
    }

    /**
     * Forgets the result, e.g. after the schema was updated in the same process.
     */
    public function reset(): void
    {
        $this->missing = null;
        $this->unknown = [];
    }

    /**
     * @return array<string, list<string>>
     */
    private function getMissing(): array
    {
        if ($this->missing !== null) {
            return $this->missing;
        }
        $this->missing = [];
        foreach (self::REQUIRED_COLUMNS as $table => $required) {
            try {
                // listTableColumns() exists in every Doctrine DBAL 4 release TYPO3 13.4 and 14.3 ship
                $existing = array_map(
                    static fn(string $name): string => strtolower(trim($name, '`"[]')),
                    array_keys($this->connectionPool->getConnectionForTable($table)->createSchemaManager()->listTableColumns($table)),
                );
            } catch (\Throwable $exception) {
                // Unknown state: deliveries are not blocked, but the columns are not written either
                $this->logger->warning('Could not read the columns of {table}: {error}', ['table' => $table, 'error' => $exception->getMessage()]);
                $this->unknown[] = $table;
                continue;
            }
            $missing = array_values(array_diff($required, $existing));
            if ($missing !== []) {
                $this->missing[$table] = $missing;
            }
        }
        return $this->missing;
    }
}
