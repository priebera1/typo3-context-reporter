<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Domain;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Domain\AttachmentMetadata;
use Priebera\ContextReporter\Domain\ContextDocument;
use Priebera\ContextReporter\Domain\DeliveryAttempt;
use Priebera\ContextReporter\Domain\DeliveryState;
use Priebera\ContextReporter\Domain\DeliveryStatus;
use Priebera\ContextReporter\Domain\Report;
use Priebera\ContextReporter\Domain\ReportSource;
use Priebera\ContextReporter\Domain\Repository\DeliveryAttemptRepository;
use Priebera\ContextReporter\Domain\Repository\ReportRepository;
use Priebera\ContextReporter\Domain\ReviewState;
use Priebera\ContextReporter\Report\ScreenshotValidator;
use Priebera\ContextReporter\Tests\Unit\Fixtures\ReportFixture;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ReportRepositoryTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['typo3/cms-filelist'];

    protected array $testExtensionsToLoad = ['priebera/typo3-context-reporter'];

    #[Test]
    public function reportAndScreenshotAreStoredAndLoadedUnchanged(): void
    {
        $binary = (string)file_get_contents(__DIR__ . '/../../Unit/Fixtures/Images/screenshot.png');
        $screenshot = (new ScreenshotValidator())->validate($binary, 1024 * 1024);
        $report = $this->createReport('CR-AAAA-BBBB-CCCC', '2026-09-01 10:00:00', screenshot: AttachmentMetadata::fromScreenshot($screenshot, 'CR-AAAA-BBBB-CCCC'));
        $repository = $this->get(ReportRepository::class);

        $stored = $repository->add($report, $screenshot);
        $loaded = $repository->findByIdentifier('CR-AAAA-BBBB-CCCC');

        self::assertGreaterThan(0, $stored->uid);
        self::assertNotNull($loaded);
        self::assertSame($stored->uid, $loaded->uid);
        self::assertSame($report->title, $loaded->title);
        self::assertSame($report->description, $loaded->description);
        self::assertSame($report->source, $loaded->source);
        self::assertSame($report->reporterUid, $loaded->reporterUid);
        self::assertSame($report->createdAt->getTimestamp(), $loaded->createdAt->getTimestamp());
        self::assertSame($report->document->toArray(), $loaded->document->toArray());
        self::assertEquals($report->screenshot, $loaded->screenshot);
        self::assertSame(DeliveryState::Local, $loaded->deliveryState);
        self::assertSame($binary, $repository->findScreenshotContent($stored->uid));

        $row = $this->get(ConnectionPool::class)->getConnectionForTable(ReportRepository::TABLE)
            ->select(['subject_type', 'subject_table', 'subject_uid', 'subject_label', 'page_uid', 'site_identifier'], ReportRepository::TABLE, ['uid' => $stored->uid])
            ->fetchAssociative();
        self::assertSame(
            ['subject_type' => 'record', 'subject_table' => 'tt_content', 'subject_uid' => 12, 'subject_label' => 'Hero teaser', 'page_uid' => 1, 'site_identifier' => 'main'],
            array_map(static fn(mixed $value): mixed => is_numeric($value) ? (int)$value : $value, $row ?: []),
        );
    }

    #[Test]
    public function unknownReportsAreNotFound(): void
    {
        self::assertNull($this->get(ReportRepository::class)->findByIdentifier('CR-0000-0000-0000'));
        self::assertNull($this->get(ReportRepository::class)->findScreenshotContent(4711));
    }

    #[Test]
    public function reportsAreListedNewestFirstAndCanBeFilteredByDeliveryState(): void
    {
        $repository = $this->get(ReportRepository::class);
        $first = $repository->add($this->createReport('CR-AAAA-0000-0001', '2026-09-01 10:00:00'));
        $repository->add($this->createReport('CR-AAAA-0000-0002', '2026-09-02 10:00:00'));
        $third = $repository->add($this->createReport('CR-AAAA-0000-0003', '2026-09-03 10:00:00'));
        $repository->updateDeliveryState($first->uid, DeliveryState::Failed);
        $repository->updateDeliveryState($third->uid, DeliveryState::Failed);

        self::assertSame(
            ['CR-AAAA-0000-0003', 'CR-AAAA-0000-0002', 'CR-AAAA-0000-0001'],
            array_map(static fn(Report $report): string => $report->identifier, $repository->findLatest(0, 10)),
        );
        self::assertSame(
            ['CR-AAAA-0000-0002'],
            array_map(static fn(Report $report): string => $report->identifier, $repository->findLatest(1, 1)),
        );
        self::assertSame(
            ['CR-AAAA-0000-0003', 'CR-AAAA-0000-0001'],
            array_map(static fn(Report $report): string => $report->identifier, $repository->findLatest(0, 10, DeliveryState::Failed)),
        );
        self::assertSame(3, $repository->count());
        self::assertSame(2, $repository->count(DeliveryState::Failed));
        self::assertSame(0, $repository->count(DeliveryState::Delivered));
        self::assertSame(2, $repository->countByReporterSince(3, (new \DateTimeImmutable('2026-09-02 00:00:00'))->getTimestamp()));
    }

    #[Test]
    public function deletingAReportRemovesItsAttachmentAndDeliveryHistory(): void
    {
        $binary = (string)file_get_contents(__DIR__ . '/../../Unit/Fixtures/Images/screenshot.png');
        $screenshot = (new ScreenshotValidator())->validate($binary, 1024 * 1024);
        $repository = $this->get(ReportRepository::class);
        $kept = $repository->add($this->createReport('CR-KEEP-0000-0001', '2026-09-01 10:00:00'));
        $removed = $repository->add(
            $this->createReport('CR-GONE-0000-0001', '2026-09-01 11:00:00', screenshot: AttachmentMetadata::fromScreenshot($screenshot, 'CR-GONE-0000-0001')),
            $screenshot,
        );
        $attempts = $this->get(DeliveryAttemptRepository::class);
        foreach ([$kept, $removed] as $report) {
            $attempts->add(new DeliveryAttempt($report->uid, 'webhook', 1, DeliveryStatus::Failed, new \DateTimeImmutable(), 'https://hooks.example.com', 3, 500, 'HTTP 500'));
        }

        $repository->delete($removed->uid);

        self::assertNull($repository->findByIdentifier('CR-GONE-0000-0001'));
        self::assertNull($repository->findScreenshotContent($removed->uid));
        self::assertSame([], $attempts->findByReportUid($removed->uid));
        self::assertNotNull($repository->findByIdentifier('CR-KEEP-0000-0001'));
        self::assertCount(1, $attempts->findByReportUid($kept->uid));
    }

    #[Test]
    public function retentionCleanupRemovesOnlyOldReports(): void
    {
        $repository = $this->get(ReportRepository::class);
        $repository->add($this->createReport('CR-OLD0-0000-0001', '2026-01-01 10:00:00'));
        $repository->add($this->createReport('CR-OLD0-0000-0002', '2026-02-01 10:00:00'));
        $repository->add($this->createReport('CR-NEW0-0000-0001', '2026-09-01 10:00:00'));
        $threshold = (new \DateTimeImmutable('2026-06-01 00:00:00'))->getTimestamp();

        self::assertSame(2, $repository->countCreatedBefore($threshold));
        self::assertSame(2, $repository->deleteCreatedBefore($threshold));
        self::assertSame(0, $repository->countCreatedBefore($threshold));
        self::assertSame(1, $repository->count());
    }

    #[Test]
    public function reportsAreOpenUntilResolvedAndCanBeReopened(): void
    {
        $repository = $this->get(ReportRepository::class);
        $report = $repository->add($this->createReport('CR-REVW-0000-0001', '2026-09-01 10:00:00'));

        $open = $repository->findByIdentifier('CR-REVW-0000-0001');
        self::assertSame(ReviewState::Open, $open?->reviewState);
        self::assertNull($open->resolvedAt);
        self::assertSame(0, $open->resolvedBy);

        self::assertTrue($repository->markResolved($report->uid, 7, 1790000000));
        self::assertFalse($repository->markResolved($report->uid, 8, 1790000100), 'A resolved report keeps its first resolution');
        $resolved = $repository->findByIdentifier('CR-REVW-0000-0001');
        self::assertSame(ReviewState::Resolved, $resolved?->reviewState);
        self::assertSame(1790000000, $resolved->resolvedAt?->getTimestamp());
        self::assertSame(7, $resolved->resolvedBy);
        self::assertSame(DeliveryState::Local, $resolved->deliveryState, 'The review state is independent of the delivery state');

        self::assertTrue($repository->reopen($report->uid));
        self::assertFalse($repository->reopen($report->uid));
        $reopened = $repository->findByIdentifier('CR-REVW-0000-0001');
        self::assertSame(ReviewState::Open, $reopened?->reviewState);
        self::assertNull($reopened->resolvedAt);
        self::assertSame(0, $reopened->resolvedBy);

        self::assertFalse($repository->markResolved(4711, 7, 1790000000));
    }

    #[Test]
    public function reportsCanBeFilteredByReviewAndDeliveryState(): void
    {
        $repository = $this->get(ReportRepository::class);
        $first = $repository->add($this->createReport('CR-REVW-0000-0001', '2026-09-01 10:00:00'));
        $second = $repository->add($this->createReport('CR-REVW-0000-0002', '2026-09-02 10:00:00'));
        $repository->add($this->createReport('CR-REVW-0000-0003', '2026-09-03 10:00:00'));
        $repository->markResolved($first->uid, 1, 1790000000);
        $repository->updateDeliveryState($first->uid, DeliveryState::Failed);
        $repository->updateDeliveryState($second->uid, DeliveryState::Failed);

        $identifiers = static fn(array $reports): array => array_map(static fn(Report $report): string => $report->identifier, $reports);
        self::assertSame(['CR-REVW-0000-0003', 'CR-REVW-0000-0002'], $identifiers($repository->findLatest(0, 10, null, ReviewState::Open)));
        self::assertSame(['CR-REVW-0000-0001'], $identifiers($repository->findLatest(0, 10, null, ReviewState::Resolved)));
        self::assertSame(['CR-REVW-0000-0002'], $identifiers($repository->findLatest(0, 10, DeliveryState::Failed, ReviewState::Open)));
        self::assertSame(2, $repository->count(null, ReviewState::Open));
        self::assertSame(1, $repository->count(DeliveryState::Failed, ReviewState::Resolved));
        self::assertSame(3, $repository->count());
    }

    #[Test]
    public function storageStatisticsCountReportsScreenshotsAndDeliveries(): void
    {
        $repository = $this->get(ReportRepository::class);
        self::assertNull($repository->findOldestCreationTime());
        self::assertSame(['reports' => 0, 'openReports' => 0, 'screenshots' => 0, 'screenshotBytes' => 0, 'deliveries' => 0], $repository->getStorageStatistics());

        $binary = (string)file_get_contents(__DIR__ . '/../../Unit/Fixtures/Images/screenshot.png');
        $screenshot = (new ScreenshotValidator())->validate($binary, 1024 * 1024);
        $attempts = $this->get(DeliveryAttemptRepository::class);
        $old = $repository->add($this->createReport('CR-STAT-0000-0001', '2026-01-01 10:00:00', AttachmentMetadata::fromScreenshot($screenshot, 'CR-STAT-0000-0001')), $screenshot);
        $oldResolved = $repository->add($this->createReport('CR-STAT-0000-0002', '2026-02-01 10:00:00'));
        $new = $repository->add($this->createReport('CR-STAT-0000-0003', '2026-09-01 10:00:00', AttachmentMetadata::fromScreenshot($screenshot, 'CR-STAT-0000-0003')), $screenshot);
        $repository->markResolved($oldResolved->uid, 1, 1790000000);
        foreach ([$old, $oldResolved, $oldResolved, $new] as $report) {
            $attempts->add(new DeliveryAttempt($report->uid, 'webhook', 1, DeliveryStatus::Failed, new \DateTimeImmutable(), 'https://hooks.example.com', 3, 500, 'HTTP 500'));
        }

        self::assertSame(
            ['reports' => 3, 'openReports' => 2, 'screenshots' => 2, 'screenshotBytes' => 2 * strlen($binary), 'deliveries' => 4],
            $repository->getStorageStatistics(),
        );
        self::assertSame(
            ['reports' => 2, 'openReports' => 1, 'screenshots' => 1, 'screenshotBytes' => strlen($binary), 'deliveries' => 3],
            $repository->getStorageStatistics((new \DateTimeImmutable('2026-06-01 00:00:00'))->getTimestamp()),
        );
        self::assertSame((new \DateTimeImmutable('2026-01-01 10:00:00'))->getTimestamp(), $repository->findOldestCreationTime());
    }

    #[Test]
    public function orphanedAttachmentsAndDeliveriesAreFoundAndRemoved(): void
    {
        $binary = (string)file_get_contents(__DIR__ . '/../../Unit/Fixtures/Images/screenshot.png');
        $screenshot = (new ScreenshotValidator())->validate($binary, 1024 * 1024);
        $repository = $this->get(ReportRepository::class);
        $report = $repository->add($this->createReport('CR-ORPH-0000-0001', '2026-09-01 10:00:00', AttachmentMetadata::fromScreenshot($screenshot, 'CR-ORPH-0000-0001')), $screenshot);
        $attempts = $this->get(DeliveryAttemptRepository::class);
        $attempts->add(new DeliveryAttempt($report->uid, 'email', 1, DeliveryStatus::Succeeded, new \DateTimeImmutable(), '1 recipient', 3, null, 'sent'));
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable(ReportRepository::TABLE);
        $connection->insert(ReportRepository::ATTACHMENT_TABLE, ['report_uid' => 9999, 'attachment_type' => 'screenshot', 'size' => 10, 'content' => 'orphan']);
        $connection->insert(DeliveryAttemptRepository::TABLE, ['report_uid' => 9999, 'destination' => 'email', 'status' => 'failed']);
        $connection->insert(DeliveryAttemptRepository::TABLE, ['report_uid' => 9998, 'destination' => 'webhook', 'status' => 'failed']);

        self::assertSame(['attachments' => 1, 'deliveries' => 2], $repository->countOrphans());
        self::assertSame(['attachments' => 1, 'deliveries' => 2], $repository->deleteOrphans());
        self::assertSame(['attachments' => 0, 'deliveries' => 0], $repository->countOrphans());
        self::assertSame($binary, $repository->findScreenshotContent($report->uid));
        self::assertCount(1, $attempts->findByReportUid($report->uid));
    }

    #[Test]
    public function deliveryAttemptsAreStoredInOrderWithoutEmptyResponseCodes(): void
    {
        $report = $this->get(ReportRepository::class)->add($this->createReport('CR-AAAA-0000-0009', '2026-09-01 10:00:00'));
        $repository = $this->get(DeliveryAttemptRepository::class);

        $repository->add(new DeliveryAttempt($report->uid, 'email', 1, DeliveryStatus::Succeeded, new \DateTimeImmutable('@1790000000'), '1 recipient', 3, null, 'sent'));
        $repository->add(new DeliveryAttempt($report->uid, 'webhook', 1, DeliveryStatus::Succeeded, new \DateTimeImmutable('@1790000001'), 'https://x.example', 3, 202, 'HTTP 202', 'REF-1', 'https://desk.example.com/1', 120));

        $attempts = $repository->findByReportUid($report->uid);
        self::assertCount(2, $attempts);
        self::assertSame('email', $attempts[0]->destination);
        self::assertNull($attempts[0]->responseCode);
        self::assertSame(202, $attempts[1]->responseCode);
        self::assertSame('REF-1', $attempts[1]->externalReference);
        self::assertSame('https://desk.example.com/1', $attempts[1]->externalUrl);
        self::assertSame(120, $attempts[1]->durationMs);
        self::assertSame(1, $repository->countByReportAndDestination($report->uid, 'webhook'));
    }

    private function createReport(string $identifier, string $createdAt, ?AttachmentMetadata $screenshot = null): Report
    {
        return new Report(
            identifier: $identifier,
            createdAt: new \DateTimeImmutable($createdAt),
            reporterUid: 3,
            source: ReportSource::ContextMenu,
            title: 'Übersicht zeigt „keine Bilder“',
            description: "Line one\nLine two <b>not html</b>",
            document: ContextDocument::fromArray(ReportFixture::document()),
            screenshot: $screenshot,
        );
    }
}
