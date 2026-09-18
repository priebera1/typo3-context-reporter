<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Domain\Repository;

use Priebera\ContextReporter\Domain\AttachmentMetadata;
use Priebera\ContextReporter\Domain\ContextDocument;
use Priebera\ContextReporter\Domain\DeliveryState;
use Priebera\ContextReporter\Domain\Report;
use Priebera\ContextReporter\Domain\ReportSource;
use Priebera\ContextReporter\Domain\ReviewState;
use Priebera\ContextReporter\Report\Screenshot;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Stores reports and their attachments. Apart from the aggregated delivery
 * state and the review state, stored reports are never modified.
 *
 * Deleting a report always deletes its attachments and delivery history in
 * the same transaction.
 *
 * @internal
 */
class ReportRepository
{
    public const TABLE = 'tx_contextreporter_report';
    public const ATTACHMENT_TABLE = 'tx_contextreporter_attachment';

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
    private const LIST_COLUMNS = [
        'uid', 'crdate', 'identifier', 'reporter_uid', 'source', 'title', 'description', 'summary', 'context', 'delivery_state',
        'review_state', 'resolved_at', 'resolved_by',
    ];
    private const DELETE_BATCH_SIZE = 200;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function add(Report $report, ?Screenshot $screenshot = null): Report
    {
        $connection = $this->getConnection();
        return $connection->transactional(function (Connection $connection) use ($report, $screenshot): Report {
            $document = $report->document;
            $subject = $document->getSubject();
            $connection->insert(self::TABLE, [
                'crdate' => $report->createdAt->getTimestamp(),
                'identifier' => $report->identifier,
                'reporter_uid' => $report->reporterUid,
                'source' => $report->source->value,
                'title' => mb_substr($report->title, 0, 255),
                'description' => $report->description,
                'subject_type' => $document->getSubjectType()->value,
                'subject_table' => (string)($subject['table'] ?? ''),
                'subject_uid' => max(0, (int)($subject['uid'] ?? 0)),
                'subject_label' => mb_substr($document->getSubjectLabel(), 0, 255),
                'page_uid' => $document->getPageUid(),
                'site_identifier' => mb_substr($document->getSiteIdentifier(), 0, 255),
                'summary' => $document->getSummary(),
                'context' => json_encode($document->toArray(), self::JSON_FLAGS),
                'delivery_state' => $report->deliveryState->value,
                'review_state' => $report->reviewState->value,
                'resolved_at' => $report->resolvedAt?->getTimestamp() ?? 0,
                'resolved_by' => $report->resolvedBy,
            ]);
            $uid = (int)$connection->lastInsertId();

            if ($screenshot !== null && $report->screenshot !== null) {
                $metadata = $report->screenshot;
                $connection->insert(
                    self::ATTACHMENT_TABLE,
                    [
                        'report_uid' => $uid,
                        'crdate' => $report->createdAt->getTimestamp(),
                        'attachment_type' => $metadata->type,
                        'filename' => $metadata->filename,
                        'media_type' => $metadata->mediaType,
                        'size' => $metadata->size,
                        'width' => $metadata->width,
                        'height' => $metadata->height,
                        'sha256' => $metadata->sha256,
                        'content' => $screenshot->content,
                    ],
                    ['content' => Connection::PARAM_LOB],
                );
            }
            return $report->withPersistenceIdentity($uid);
        });
    }

    public function findByIdentifier(string $identifier): ?Report
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $row = $queryBuilder
            ->select(...self::LIST_COLUMNS)
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($identifier)))
            ->executeQuery()
            ->fetchAssociative();
        return is_array($row) ? $this->hydrateMany([$row])[0] : null;
    }

    /**
     * @return list<Report>
     */
    public function findLatest(int $offset, int $limit, ?DeliveryState $deliveryState = null, ?ReviewState $reviewState = null): array
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $queryBuilder
            ->select(...self::LIST_COLUMNS)
            ->from(self::TABLE)
            ->orderBy('crdate', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit));
        $this->applyStateFilter($queryBuilder, $deliveryState, $reviewState);
        return $this->hydrateMany($queryBuilder->executeQuery()->fetchAllAssociative());
    }

    public function count(?DeliveryState $deliveryState = null, ?ReviewState $reviewState = null): int
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $queryBuilder->count('uid')->from(self::TABLE);
        $this->applyStateFilter($queryBuilder, $deliveryState, $reviewState);
        return (int)$queryBuilder->executeQuery()->fetchOne();
    }

    /**
     * @return bool false when the report does not exist or is already resolved
     */
    public function markResolved(int $reportUid, int $userId, int $timestamp): bool
    {
        return $this->getConnection()->update(
            self::TABLE,
            ['review_state' => ReviewState::Resolved->value, 'resolved_at' => $timestamp, 'resolved_by' => max(0, $userId)],
            ['uid' => $reportUid, 'review_state' => ReviewState::Open->value],
        ) > 0;
    }

    /**
     * @return bool false when the report does not exist or is already open
     */
    public function reopen(int $reportUid): bool
    {
        return $this->getConnection()->update(
            self::TABLE,
            ['review_state' => ReviewState::Open->value, 'resolved_at' => 0, 'resolved_by' => 0],
            ['uid' => $reportUid, 'review_state' => ReviewState::Resolved->value],
        ) > 0;
    }

    public function countByReporterSince(int $reporterUid, int $timestamp): int
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('reporter_uid', $queryBuilder->createNamedParameter($reporterUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($timestamp, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();
    }

    public function findScreenshotContent(int $reportUid): ?string
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $content = $queryBuilder
            ->select('content')
            ->from(self::ATTACHMENT_TABLE)
            ->where(
                $queryBuilder->expr()->eq('report_uid', $queryBuilder->createNamedParameter($reportUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('attachment_type', $queryBuilder->createNamedParameter(AttachmentMetadata::TYPE_SCREENSHOT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        if (is_resource($content)) {
            $content = stream_get_contents($content);
        }
        return is_string($content) && $content !== '' ? $content : null;
    }

    public function updateDeliveryState(int $reportUid, DeliveryState $deliveryState): void
    {
        $this->getConnection()->update(
            self::TABLE,
            ['delivery_state' => $deliveryState->value],
            ['uid' => $reportUid],
        );
    }

    public function delete(int $reportUid): void
    {
        $this->deleteByUids([$reportUid]);
    }

    public function countCreatedBefore(int $timestamp): int
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->lt('crdate', $queryBuilder->createNamedParameter($timestamp, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return int Number of removed reports
     */
    public function deleteCreatedBefore(int $timestamp): int
    {
        $removed = 0;
        do {
            $queryBuilder = $this->getConnection()->createQueryBuilder();
            $uids = $queryBuilder
                ->select('uid')
                ->from(self::TABLE)
                ->where($queryBuilder->expr()->lt('crdate', $queryBuilder->createNamedParameter($timestamp, Connection::PARAM_INT)))
                ->orderBy('uid')
                ->setMaxResults(self::DELETE_BATCH_SIZE)
                ->executeQuery()
                ->fetchFirstColumn();
            $uids = array_map(intval(...), $uids);
            $this->deleteByUids($uids);
            $removed += count($uids);
        } while ($uids !== []);
        return $removed;
    }

    /**
     * Stored data, optionally limited to reports created before the given time.
     * Screenshot sizes are the byte sizes recorded when they were stored.
     *
     * @return array{reports: int, openReports: int, screenshots: int, screenshotBytes: int, deliveries: int}
     */
    public function getStorageStatistics(?int $createdBefore = null): array
    {
        $connection = $this->getConnection();

        $reports = $connection->createQueryBuilder();
        $reports->count('uid')->from(self::TABLE);
        $openReports = $connection->createQueryBuilder();
        $openReports->count('uid')->from(self::TABLE)->where(
            $openReports->expr()->eq('review_state', $openReports->createNamedParameter(ReviewState::Open->value)),
        );
        if ($createdBefore !== null) {
            foreach ([$reports, $openReports] as $queryBuilder) {
                $queryBuilder->andWhere($queryBuilder->expr()->lt('crdate', $queryBuilder->createNamedParameter($createdBefore, Connection::PARAM_INT)));
            }
        }

        $screenshots = $this->createJoinedQuery(self::ATTACHMENT_TABLE, $createdBefore);
        $screenshots
            ->selectLiteral(
                'COUNT(' . $screenshots->quoteIdentifier('child.uid') . ') AS ' . $screenshots->quoteIdentifier('files'),
                'SUM(' . $screenshots->quoteIdentifier('child.size') . ') AS ' . $screenshots->quoteIdentifier('bytes'),
            )
            ->andWhere($screenshots->expr()->eq('child.attachment_type', $screenshots->createNamedParameter(AttachmentMetadata::TYPE_SCREENSHOT)));
        $screenshotRow = $screenshots->executeQuery()->fetchAssociative() ?: [];

        $deliveries = $this->createJoinedQuery(DeliveryAttemptRepository::TABLE, $createdBefore);
        $deliveries->selectLiteral('COUNT(' . $deliveries->quoteIdentifier('child.uid') . ')');

        return [
            'reports' => (int)$reports->executeQuery()->fetchOne(),
            'openReports' => (int)$openReports->executeQuery()->fetchOne(),
            'screenshots' => (int)($screenshotRow['files'] ?? 0),
            'screenshotBytes' => (int)($screenshotRow['bytes'] ?? 0),
            'deliveries' => (int)$deliveries->executeQuery()->fetchOne(),
        ];
    }

    public function findOldestCreationTime(): ?int
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $oldest = $queryBuilder
            ->selectLiteral('MIN(' . $queryBuilder->quoteIdentifier('crdate') . ')')
            ->from(self::TABLE)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($oldest) ? (int)$oldest : null;
    }

    /**
     * Attachments and delivery attempts whose report no longer exists.
     *
     * @return array{attachments: int, deliveries: int}
     */
    public function countOrphans(): array
    {
        return [
            'attachments' => count($this->findOrphanUids(self::ATTACHMENT_TABLE)),
            'deliveries' => count($this->findOrphanUids(DeliveryAttemptRepository::TABLE)),
        ];
    }

    /**
     * @return array{attachments: int, deliveries: int} Number of removed rows
     */
    public function deleteOrphans(): array
    {
        $removed = ['attachments' => 0, 'deliveries' => 0];
        foreach (['attachments' => self::ATTACHMENT_TABLE, 'deliveries' => DeliveryAttemptRepository::TABLE] as $key => $table) {
            foreach (array_chunk($this->findOrphanUids($table), self::DELETE_BATCH_SIZE) as $uids) {
                $queryBuilder = $this->getConnection()->createQueryBuilder();
                $removed[$key] += $queryBuilder
                    ->delete($table)
                    ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
                    ->executeStatement();
            }
        }
        return $removed;
    }

    /**
     * @param list<int> $uids
     */
    private function deleteByUids(array $uids): void
    {
        if ($uids === []) {
            return;
        }
        $this->getConnection()->transactional(static function (Connection $connection) use ($uids): void {
            foreach ([self::ATTACHMENT_TABLE => 'report_uid', DeliveryAttemptRepository::TABLE => 'report_uid', self::TABLE => 'uid'] as $table => $field) {
                $queryBuilder = $connection->createQueryBuilder();
                $queryBuilder
                    ->delete($table)
                    ->where($queryBuilder->expr()->in($field, $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
                    ->executeStatement();
            }
        });
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Report>
     */
    private function hydrateMany(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $attachments = $this->findScreenshotMetadata(array_map(static fn(array $row): int => (int)$row['uid'], $rows));
        $reports = [];
        foreach ($rows as $row) {
            $context = json_decode((string)($row['context'] ?? ''), true);
            $reports[] = new Report(
                identifier: (string)$row['identifier'],
                createdAt: $this->createDate((int)$row['crdate']),
                reporterUid: (int)$row['reporter_uid'],
                source: ReportSource::tryFrom((string)$row['source']) ?? ReportSource::Toolbar,
                title: (string)$row['title'],
                description: (string)($row['description'] ?? ''),
                document: ContextDocument::fromArray(is_array($context) ? $context : []),
                screenshot: $attachments[(int)$row['uid']] ?? null,
                uid: (int)$row['uid'],
                deliveryState: DeliveryState::tryFrom((string)$row['delivery_state']) ?? DeliveryState::Local,
                reviewState: ReviewState::tryFrom((string)$row['review_state']) ?? ReviewState::Open,
                resolvedAt: (int)$row['resolved_at'] > 0 ? $this->createDate((int)$row['resolved_at']) : null,
                resolvedBy: (int)$row['resolved_by'],
            );
        }
        return $reports;
    }

    /**
     * @param list<int> $reportUids
     * @return array<int, AttachmentMetadata>
     */
    private function findScreenshotMetadata(array $reportUids): array
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $rows = $queryBuilder
            ->select('report_uid', 'attachment_type', 'filename', 'media_type', 'size', 'width', 'height', 'sha256')
            ->from(self::ATTACHMENT_TABLE)
            ->where(
                $queryBuilder->expr()->in('report_uid', $queryBuilder->createNamedParameter($reportUids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('attachment_type', $queryBuilder->createNamedParameter(AttachmentMetadata::TYPE_SCREENSHOT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['report_uid']] = new AttachmentMetadata(
                type: (string)$row['attachment_type'],
                filename: (string)$row['filename'],
                mediaType: (string)$row['media_type'],
                size: (int)$row['size'],
                width: (int)$row['width'],
                height: (int)$row['height'],
                sha256: (string)$row['sha256'],
            );
        }
        return $result;
    }

    private function applyStateFilter(QueryBuilder $queryBuilder, ?DeliveryState $deliveryState, ?ReviewState $reviewState): void
    {
        if ($deliveryState !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('delivery_state', $queryBuilder->createNamedParameter($deliveryState->value)));
        }
        if ($reviewState !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('review_state', $queryBuilder->createNamedParameter($reviewState->value)));
        }
    }

    /**
     * Rows of a child table (alias "child") joined with their report, optionally
     * limited to reports created before the given time.
     */
    private function createJoinedQuery(string $childTable, ?int $createdBefore): QueryBuilder
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $queryBuilder
            ->from($childTable, 'child')
            ->join('child', self::TABLE, 'report', $queryBuilder->expr()->eq('report.uid', $queryBuilder->quoteIdentifier('child.report_uid')));
        if ($createdBefore !== null) {
            $queryBuilder->where($queryBuilder->expr()->lt('report.crdate', $queryBuilder->createNamedParameter($createdBefore, Connection::PARAM_INT)));
        }
        return $queryBuilder;
    }

    /**
     * @return list<int>
     */
    private function findOrphanUids(string $childTable): array
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $uids = $queryBuilder
            ->select('child.uid')
            ->from($childTable, 'child')
            ->leftJoin('child', self::TABLE, 'report', $queryBuilder->expr()->eq('report.uid', $queryBuilder->quoteIdentifier('child.report_uid')))
            ->where($queryBuilder->expr()->isNull('report.uid'))
            ->orderBy('child.uid')
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(intval(...), $uids);
    }

    private function createDate(int $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    private function getConnection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
