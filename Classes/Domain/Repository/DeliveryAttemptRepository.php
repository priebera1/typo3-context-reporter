<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Domain\Repository;

use Priebera\ContextReporter\Domain\DeliveryAttempt;
use Priebera\ContextReporter\Domain\DeliveryStatus;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Append-only delivery audit trail.
 *
 * @internal
 */
class DeliveryAttemptRepository
{
    public const TABLE = 'tx_contextreporter_delivery';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function add(DeliveryAttempt $attempt): DeliveryAttempt
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'report_uid' => $attempt->reportUid,
            'crdate' => $attempt->createdAt->getTimestamp(),
            'destination' => $attempt->destination,
            'attempt' => $attempt->attempt,
            'status' => $attempt->status->value,
            'target' => mb_substr($attempt->target, 0, 255),
            'triggered_by' => $attempt->triggeredBy,
            'response_code' => max(0, (int)$attempt->responseCode),
            'message' => $attempt->message,
            'external_reference' => mb_substr($attempt->externalReference, 0, 255),
            'external_url' => $attempt->externalUrl,
            'duration_ms' => max(0, $attempt->durationMs),
        ]);
        return new DeliveryAttempt(
            reportUid: $attempt->reportUid,
            destination: $attempt->destination,
            attempt: $attempt->attempt,
            status: $attempt->status,
            createdAt: $attempt->createdAt,
            target: $attempt->target,
            triggeredBy: $attempt->triggeredBy,
            responseCode: $attempt->responseCode,
            message: $attempt->message,
            externalReference: $attempt->externalReference,
            externalUrl: $attempt->externalUrl,
            durationMs: $attempt->durationMs,
            uid: (int)$connection->lastInsertId(),
        );
    }

    /**
     * @return list<DeliveryAttempt> Oldest first
     */
    public function findByReportUid(int $reportUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('report_uid', $queryBuilder->createNamedParameter($reportUid, Connection::PARAM_INT)))
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
        return array_map($this->hydrate(...), $rows);
    }

    public function countByReportAndDestination(int $reportUid, string $destination): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('report_uid', $queryBuilder->createNamedParameter($reportUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('destination', $queryBuilder->createNamedParameter($destination)),
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): DeliveryAttempt
    {
        $responseCode = (int)($row['response_code'] ?? 0);
        return new DeliveryAttempt(
            reportUid: (int)$row['report_uid'],
            destination: (string)$row['destination'],
            attempt: (int)$row['attempt'],
            status: DeliveryStatus::tryFrom((string)$row['status']) ?? DeliveryStatus::Failed,
            createdAt: (new \DateTimeImmutable('@' . (int)$row['crdate']))->setTimezone(new \DateTimeZone(date_default_timezone_get())),
            target: (string)$row['target'],
            triggeredBy: (int)$row['triggered_by'],
            responseCode: $responseCode > 0 ? $responseCode : null,
            message: (string)($row['message'] ?? ''),
            externalReference: (string)$row['external_reference'],
            externalUrl: (string)($row['external_url'] ?? ''),
            durationMs: (int)$row['duration_ms'],
            uid: (int)$row['uid'],
        );
    }
}
