<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Retention;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Domain\AttachmentMetadata;
use Priebera\ContextReporter\Domain\ContextDocument;
use Priebera\ContextReporter\Domain\DeliveryAttempt;
use Priebera\ContextReporter\Domain\DeliveryStatus;
use Priebera\ContextReporter\Domain\Report;
use Priebera\ContextReporter\Domain\ReportSource;
use Priebera\ContextReporter\Domain\Repository\DeliveryAttemptRepository;
use Priebera\ContextReporter\Domain\Repository\ReportRepository;
use Priebera\ContextReporter\Report\ScreenshotValidator;
use Priebera\ContextReporter\Retention\RetentionService;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use Priebera\ContextReporter\Tests\Unit\Fixtures\ReportFixture;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class RetentionServiceTest extends AbstractContextReporterTestCase
{
    private const NOW = 1790000000;
    private const DAY = 86400;

    #[Test]
    public function keepForeverIsTheDefaultAndRemovesNoReports(): void
    {
        $this->addReport('CR-KEEP-0000-0001', self::NOW - 4000 * self::DAY, withScreenshot: true);
        $service = $this->get(RetentionService::class);

        $preview = $service->preview(null, self::NOW);

        self::assertSame(0, $preview->days);
        self::assertNull($preview->cutoff);
        self::assertSame(0, $preview->reports);
        self::assertFalse($preview->hasWork());
        self::assertSame(0, $service->cleanup(0, self::NOW)->reports);
        self::assertSame(1, $this->get(ReportRepository::class)->count());
    }

    #[Test]
    public function previewAndCleanupUseTheConfiguredRetention(): void
    {
        $this->configureExtension(['reporting' => ['retentionDays' => 30]]);
        $old = $this->addReport('CR-OLD0-0000-0001', self::NOW - 40 * self::DAY, withScreenshot: true);
        $this->addReport('CR-NEW0-0000-0001', self::NOW - 10 * self::DAY, withScreenshot: true);
        $this->get(DeliveryAttemptRepository::class)->add(new DeliveryAttempt($old->uid, 'email', 1, DeliveryStatus::Succeeded, new \DateTimeImmutable(), '1 recipient', 3, null, 'sent'));
        $this->get(ReportRepository::class)->markResolved($old->uid, 1, self::NOW);
        $service = $this->get(RetentionService::class);

        $preview = $service->preview(null, self::NOW);

        self::assertSame(30, $preview->days);
        self::assertSame(self::NOW - 30 * self::DAY, $preview->cutoff?->getTimestamp());
        self::assertSame(1, $preview->reports);
        self::assertSame(0, $preview->openReports);
        self::assertSame(1, $preview->screenshots);
        self::assertSame($this->getScreenshotSize(), $preview->screenshotBytes);
        self::assertSame(1, $preview->deliveries);
        self::assertTrue($preview->hasWork());

        $result = $service->cleanup(30, self::NOW);

        self::assertSame(1, $result->reports);
        $repository = $this->get(ReportRepository::class);
        self::assertNull($repository->findByIdentifier('CR-OLD0-0000-0001'));
        self::assertNotNull($repository->findByIdentifier('CR-NEW0-0000-0001'));
        self::assertNull($repository->findScreenshotContent($old->uid));
        self::assertSame([], $this->get(DeliveryAttemptRepository::class)->findByReportUid($old->uid));
        self::assertSame(['attachments' => 0, 'deliveries' => 0], $repository->countOrphans());
    }

    #[Test]
    public function cleanupAlsoRemovesOrphanedData(): void
    {
        $this->addReport('CR-KEEP-0000-0001', self::NOW - self::DAY);
        $this->get(ConnectionPool::class)->getConnectionForTable(DeliveryAttemptRepository::TABLE)
            ->insert(DeliveryAttemptRepository::TABLE, ['report_uid' => 4711, 'destination' => 'email', 'status' => 'failed']);
        $service = $this->get(RetentionService::class);

        $preview = $service->preview(null, self::NOW);
        self::assertSame(1, $preview->orphanedDeliveries);
        self::assertTrue($preview->hasWork());

        $result = $service->cleanup(0, self::NOW);
        self::assertSame(0, $result->reports);
        self::assertSame(1, $result->orphanedDeliveries);
        self::assertSame(1, $this->get(ReportRepository::class)->count());
    }

    #[Test]
    public function statisticsDescribeTheStoredData(): void
    {
        $this->addReport('CR-STAT-0000-0001', self::NOW - 5 * self::DAY, withScreenshot: true);
        $second = $this->addReport('CR-STAT-0000-0002', self::NOW - self::DAY);
        $this->get(ReportRepository::class)->markResolved($second->uid, 1, self::NOW);

        $statistics = $this->get(RetentionService::class)->getStatistics();

        self::assertSame(2, $statistics->reports);
        self::assertSame(1, $statistics->openReports);
        self::assertSame(1, $statistics->resolvedReports);
        self::assertSame(1, $statistics->screenshots);
        self::assertSame($this->getScreenshotSize(), $statistics->screenshotBytes);
        self::assertSame(0, $statistics->deliveries);
        self::assertSame(self::NOW - 5 * self::DAY, $statistics->oldestReport?->getTimestamp());
    }

    #[Test]
    public function negativeRetentionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->get(RetentionService::class)->cleanup(-1, self::NOW);
    }

    private function addReport(string $identifier, int $createdAt, bool $withScreenshot = false): Report
    {
        $screenshot = $withScreenshot
            ? (new ScreenshotValidator())->validate((string)file_get_contents(__DIR__ . '/../../Unit/Fixtures/Images/screenshot.png'), 1024 * 1024)
            : null;
        return $this->get(ReportRepository::class)->add(new Report(
            identifier: $identifier,
            createdAt: new \DateTimeImmutable('@' . $createdAt),
            reporterUid: 3,
            source: ReportSource::Toolbar,
            title: 'Report ' . $identifier,
            description: '',
            document: ContextDocument::fromArray(ReportFixture::document()),
            screenshot: $screenshot !== null ? AttachmentMetadata::fromScreenshot($screenshot, $identifier) : null,
        ), $screenshot);
    }

    private function getScreenshotSize(): int
    {
        return strlen((string)file_get_contents(__DIR__ . '/../../Unit/Fixtures/Images/screenshot.png'));
    }
}
