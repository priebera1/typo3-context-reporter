<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Domain;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\ExtensionSettingsFactory;
use Priebera\ContextReporter\Domain\AttachmentMetadata;
use Priebera\ContextReporter\Domain\ContextDocument;
use Priebera\ContextReporter\Domain\DeliveryState;
use Priebera\ContextReporter\Domain\Report;
use Priebera\ContextReporter\Domain\ReportSource;
use Priebera\ContextReporter\Domain\Repository\DeliveryAttemptRepository;
use Priebera\ContextReporter\Domain\Repository\ReportRepository;
use Priebera\ContextReporter\Domain\ReviewState;
use Priebera\ContextReporter\Report\ScreenshotValidator;
use Priebera\ContextReporter\Retention\RetentionService;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use Priebera\ContextReporter\Tests\Unit\Fixtures\ReportFixture;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;

/**
 * Schema installation and upgrade on the database the functional tests run on
 * (SQLite by default, MariaDB or MySQL with `runTests.sh -d`).
 */
final class DatabaseSchemaTest extends AbstractContextReporterTestCase
{
    private const NOW = 1790000000;
    private const DAY = 86400;
    private const SCREENSHOT = __DIR__ . '/../../Unit/Fixtures/Images/screenshot.png';

    /**
     * Added to tx_contextreporter_report after the first development versions
     */
    private const REVIEW_COLUMNS = ['review_state', 'resolved_at', 'resolved_by'];

    #[Test]
    public function installedSchemaHasNoPendingChanges(): void
    {
        self::assertSame([], $this->getPendingChanges());
    }

    #[Test]
    public function largestAllowedScreenshotIsStoredUnchanged(): void
    {
        $maxBytes = ExtensionSettingsFactory::MAX_SCREENSHOT_KB * 1024;
        $content = $this->createScreenshotContent($maxBytes);
        $screenshot = (new ScreenshotValidator())->validate($content, $maxBytes);
        $repository = $this->get(ReportRepository::class);

        $report = $repository->add(new Report(
            identifier: 'CR-MAXS-0000-0001',
            createdAt: new \DateTimeImmutable('@' . self::NOW),
            reporterUid: 3,
            source: ReportSource::Toolbar,
            title: 'Largest screenshot',
            description: '',
            document: ContextDocument::fromArray(ReportFixture::document()),
            screenshot: AttachmentMetadata::fromScreenshot($screenshot, 'CR-MAXS-0000-0001'),
        ), $screenshot);

        $stored = (string)$repository->findScreenshotContent($report->uid);
        self::assertSame($maxBytes, strlen($stored));
        self::assertSame(hash('sha256', $content), hash('sha256', $stored));
    }

    #[Test]
    public function upgradeFromTheSchemaWithoutReviewStateKeepsExistingReports(): void
    {
        $connection = $this->getConnection();
        foreach (self::REVIEW_COLUMNS as $column) {
            $connection->executeStatement(sprintf(
                'ALTER TABLE %s DROP COLUMN %s',
                $connection->quoteIdentifier(ReportRepository::TABLE),
                $connection->quoteIdentifier($column),
            ));
        }
        self::assertNotSame([], $this->getPendingChanges());

        $screenshot = $this->createScreenshotContent(1024 * 1024);
        $old = $this->insertLegacyReport('CR-LEGA-0000-0001', self::NOW - 40 * self::DAY, $screenshot);
        $recent = $this->insertLegacyReport('CR-LEGA-0000-0002', self::NOW - self::DAY, $screenshot);

        // What `vendor/bin/typo3 extension:setup` does with the database
        $this->updateDatabaseSchema();

        self::assertSame([], $this->getPendingChanges());
        $repository = $this->get(ReportRepository::class);
        $deliveries = $this->get(DeliveryAttemptRepository::class);

        $report = $repository->findByIdentifier('CR-LEGA-0000-0001');
        self::assertNotNull($report);
        self::assertSame($old, $report->uid);
        self::assertSame(ReviewState::Open, $report->reviewState);
        self::assertNull($report->resolvedAt);
        self::assertSame(0, $report->resolvedBy);
        self::assertSame(DeliveryState::Failed, $report->deliveryState);
        self::assertSame('Legacy CR-LEGA-0000-0001', $report->title);
        self::assertSame(ReportFixture::document(), $report->document->toArray());
        self::assertSame(hash('sha256', $screenshot), hash('sha256', (string)$repository->findScreenshotContent($old)));
        self::assertCount(1, $deliveries->findByReportUid($old));
        self::assertSame(2, $repository->count(null, ReviewState::Open));
        self::assertSame(0, $repository->count(null, ReviewState::Resolved));

        self::assertTrue($repository->markResolved($old, 1, self::NOW));
        self::assertFalse($repository->markResolved($old, 1, self::NOW));
        $report = $repository->findByIdentifier('CR-LEGA-0000-0001');
        self::assertSame(ReviewState::Resolved, $report?->reviewState);
        self::assertSame(self::NOW, $report->resolvedAt?->getTimestamp());
        self::assertSame(1, $report->resolvedBy);
        self::assertTrue($repository->reopen($old));
        self::assertFalse($repository->reopen($old));
        $report = $repository->findByIdentifier('CR-LEGA-0000-0001');
        self::assertSame(ReviewState::Open, $report?->reviewState);
        self::assertNull($report->resolvedAt);
        self::assertSame(0, $report->resolvedBy);

        $added = $repository->add(new Report(
            identifier: 'CR-NEW0-0000-0001',
            createdAt: new \DateTimeImmutable('@' . self::NOW),
            reporterUid: 3,
            source: ReportSource::Toolbar,
            title: 'Created after the upgrade',
            description: '',
            document: ContextDocument::fromArray(ReportFixture::document()),
        ));
        self::assertSame(ReviewState::Open, $repository->findByIdentifier('CR-NEW0-0000-0001')?->reviewState);

        $this->insertRow(ReportRepository::ATTACHMENT_TABLE, ['report_uid' => 4711, 'attachment_type' => 'screenshot', 'content' => $screenshot]);
        $this->insertRow(DeliveryAttemptRepository::TABLE, ['report_uid' => 4712, 'destination' => 'webhook', 'status' => 'failed']);
        $retention = $this->get(RetentionService::class);

        $preview = $retention->preview(30, self::NOW);
        self::assertSame(1, $preview->reports);
        self::assertSame(1, $preview->openReports);
        self::assertSame(1, $preview->screenshots);
        self::assertSame(strlen($screenshot), $preview->screenshotBytes);
        self::assertSame(1, $preview->deliveries);
        self::assertSame(1, $preview->orphanedAttachments);
        self::assertSame(1, $preview->orphanedDeliveries);

        $result = $retention->cleanup(30, self::NOW);
        self::assertSame(1, $result->reports);
        self::assertSame(1, $result->orphanedAttachments);
        self::assertSame(1, $result->orphanedDeliveries);
        self::assertNull($repository->findByIdentifier('CR-LEGA-0000-0001'));
        self::assertSame(0, $this->countRows(ReportRepository::ATTACHMENT_TABLE, $old));
        self::assertSame(0, $this->countRows(DeliveryAttemptRepository::TABLE, $old));
        self::assertSame(['attachments' => 0, 'deliveries' => 0], $repository->countOrphans());

        self::assertSame(hash('sha256', $screenshot), hash('sha256', (string)$repository->findScreenshotContent($recent)));
        $repository->delete($recent);
        self::assertNull($repository->findByIdentifier('CR-LEGA-0000-0002'));
        self::assertSame(0, $this->countRows(ReportRepository::ATTACHMENT_TABLE, $recent));
        self::assertSame(0, $this->countRows(DeliveryAttemptRepository::TABLE, $recent));
        self::assertSame(1, $repository->count());
        self::assertSame($added->uid, $repository->findLatest(0, 10)[0]->uid);
    }

    /**
     * The schema update of `extension:setup`. TYPO3 v13 runs it in
     * PackageActivationService::updateDatabase(), v14 in PackageSetup::setup();
     * both are internal, so the test applies the same statements itself.
     */
    private function updateDatabaseSchema(): void
    {
        $sqlReader = $this->get(SqlReader::class);
        $statements = $sqlReader->getCreateTableStatementArray($sqlReader->getTablesDefinitionString());
        $updateStatements = array_merge_recursive(...array_values($this->get(SchemaMigrator::class)->getUpdateSuggestions($statements)));
        $selected = [];
        foreach (['add', 'change', 'create_table', 'change_table'] as $action) {
            foreach (array_keys($updateStatements[$action] ?? []) as $hash) {
                $selected[$hash] = true;
            }
        }
        $this->get(SchemaMigrator::class)->migrate($statements, $selected);
    }

    /**
     * The update suggestions for the extension's tables, as shown by the database analyzer
     *
     * @return list<string>
     */
    private function getPendingChanges(): array
    {
        $sqlReader = $this->get(SqlReader::class);
        $statements = $sqlReader->getCreateTableStatementArray($sqlReader->getTablesDefinitionString());
        $pending = [];
        foreach ($this->get(SchemaMigrator::class)->getUpdateSuggestions($statements) as $suggestions) {
            foreach (['add', 'change', 'create_table', 'change_table'] as $action) {
                foreach ($suggestions[$action] ?? [] as $statement) {
                    if (str_contains($statement, 'tx_contextreporter_')) {
                        $pending[] = $action . ': ' . $statement;
                    }
                }
            }
        }
        return $pending;
    }

    /**
     * A report, its screenshot and a failed delivery as the first development versions stored them
     */
    private function insertLegacyReport(string $identifier, int $createdAt, string $screenshot): int
    {
        $document = ReportFixture::document();
        $this->insertRow(ReportRepository::TABLE, [
            'crdate' => $createdAt,
            'identifier' => $identifier,
            'reporter_uid' => 3,
            'source' => 'toolbar',
            'title' => 'Legacy ' . $identifier,
            'description' => 'Stored before reports had a review state',
            'subject_type' => 'record',
            'subject_table' => 'tt_content',
            'subject_uid' => 12,
            'subject_label' => 'Hero teaser',
            'page_uid' => 1,
            'site_identifier' => 'main',
            'summary' => $document['summary'],
            'context' => json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            'delivery_state' => 'failed',
        ]);
        $uid = (int)$this->getConnection()->lastInsertId();
        $this->insertRow(ReportRepository::ATTACHMENT_TABLE, [
            'report_uid' => $uid,
            'crdate' => $createdAt,
            'attachment_type' => AttachmentMetadata::TYPE_SCREENSHOT,
            'filename' => strtolower($identifier) . '-screenshot.png',
            'media_type' => 'image/png',
            'size' => strlen($screenshot),
            'width' => 3,
            'height' => 2,
            'sha256' => hash('sha256', $screenshot),
            'content' => $screenshot,
        ]);
        $this->insertRow(DeliveryAttemptRepository::TABLE, [
            'report_uid' => $uid,
            'crdate' => $createdAt,
            'destination' => 'email',
            'attempt' => 1,
            'status' => 'failed',
            'target' => '1 recipient',
            'triggered_by' => 3,
            'message' => 'Connection refused',
            'duration_ms' => 12,
        ]);
        return $uid;
    }

    /**
     * @param array<string, int|string> $row
     */
    private function insertRow(string $table, array $row): void
    {
        $types = array_key_exists('content', $row) ? ['content' => Connection::PARAM_LOB] : [];
        $this->getConnection()->insert($table, $row, $types);
    }

    private function countRows(string $table, int $reportUid): int
    {
        return $this->getConnection()->count('*', $table, ['report_uid' => $reportUid]);
    }

    /**
     * A PNG that the screenshot validator accepts, padded with every byte value to the given size
     */
    private function createScreenshotContent(int $size): string
    {
        $png = (string)file_get_contents(self::SCREENSHOT);
        $allBytes = implode('', array_map(chr(...), range(0, 255)));
        return substr($png . str_repeat($allBytes, intdiv($size, 256) + 1), 0, $size);
    }

    private function getConnection(): Connection
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable(ReportRepository::TABLE);
    }
}
