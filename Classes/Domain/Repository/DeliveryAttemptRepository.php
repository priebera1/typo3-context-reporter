<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Domain\Repository;

use Priebera\ContextReporter\Database\DatabaseSchemaState;
use Priebera\ContextReporter\Domain\DeliveryAttempt;
use Priebera\ContextReporter\Domain\DeliveryStatus;
use Priebera\ContextReporter\Hub\HubReceipt;
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
        private readonly DatabaseSchemaState $schema,
    ) {}

    public function add(DeliveryAttempt $attempt): DeliveryAttempt
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, $this->toRow($attempt));
        return $this->withUid($attempt, (int)$connection->lastInsertId());
    }

    /**
     * Records the outcome of a claimed attempt (status "sending") on its row.
     */
    public function complete(DeliveryAttempt $attempt): bool
    {
        $row = $this->toRow($attempt);
        unset($row['report_uid'], $row['destination'], $row['attempt']);
        // Only the claim itself: an outcome that arrives after its claim was given up (outcome unknown, retried by
        // a person) does not overwrite the history
        return $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            $row,
            ['uid' => $attempt->uid, 'status' => DeliveryStatus::Sending->value],
        ) > 0;
    }

    /**
     * Records a claim to send to a destination: an attempt with status
     * "sending", stored before anything leaves the installation. It is the
     * claim only if no other attempt of the report and destination was
     * recorded after the one it follows ($afterUid, the attempt that was
     * latest when the caller decided): two requests that decided on the same
     * state both write their row first and look second, so at most one of them
     * finds only its own (both giving way is possible, both sending is not).
     * A claim that lost was never sent; see giveWay() for what is kept of it.
     *
     * @param string $notSentMessage Message of the failed attempt kept when every request gave way
     * @return DeliveryAttempt|null The recorded claim ("sending": send now), a failed attempt (every request
     *                              gave way: nothing is sent, the delivery can be retried), or null when another
     *                              request is sending or has sent
     */
    public function claim(DeliveryAttempt $sending, int $afterUid, string $notSentMessage = ''): ?DeliveryAttempt
    {
        $claim = $this->add($sending);
        try {
            $others = $this->countOthersAfter($claim, $afterUid);
        } catch (\Throwable $exception) {
            // Not checked, so never sent: the claim must not stay "sending" (it would later read "may have been sent")
            $this->removeClaim($claim);
            throw $exception;
        }
        if ($others === 0) {
            return $claim;
        }
        return $this->giveWay($claim, $afterUid, $notSentMessage);
    }

    /**
     * A claim that lost to another attempt recorded after the same state. When
     * a request is sending (or has sent), it delivers, and this claim is
     * removed. When every request gave way, nobody sends; the destination must
     * not vanish from the history then. So the claim first becomes a failed
     * attempt and only then looks for one that is sending or has sent (write
     * first, look second, as in claim()): of two requests giving way at the
     * same time, at least the one that looks last keeps its failed attempt,
     * which can be retried. A request that would claim later finds it and
     * gives way as well, so nothing is sent twice.
     */
    private function giveWay(DeliveryAttempt $claim, int $afterUid, string $notSentMessage): ?DeliveryAttempt
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $marked = $connection->update(
            self::TABLE,
            ['status' => DeliveryStatus::Failed->value, 'message' => $notSentMessage],
            ['uid' => $claim->uid, 'status' => DeliveryStatus::Sending->value],
        ) > 0;
        if (!$marked) {
            return null;
        }
        try {
            $active = $this->countSendingOrSentAfter($claim, $afterUid);
        } catch (\Throwable) {
            // Not checked: give way without a record rather than risk a retry of what another request sends
            $active = 1;
        }
        if ($active > 0) {
            $connection->delete(self::TABLE, ['uid' => $claim->uid, 'status' => DeliveryStatus::Failed->value]);
            return null;
        }
        return new DeliveryAttempt(
            reportUid: $claim->reportUid,
            destination: $claim->destination,
            attempt: $claim->attempt,
            status: DeliveryStatus::Failed,
            createdAt: $claim->createdAt,
            target: $claim->target,
            triggeredBy: $claim->triggeredBy,
            message: $notSentMessage,
            uid: $claim->uid,
        );
    }

    /**
     * Other attempts after $afterUid that are sending or have been sent (not failed ones)
     */
    protected function countSendingOrSentAfter(DeliveryAttempt $claim, int $afterUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('report_uid', $queryBuilder->createNamedParameter($claim->reportUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('destination', $queryBuilder->createNamedParameter($claim->destination)),
                $queryBuilder->expr()->gt('uid', $queryBuilder->createNamedParameter($afterUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->neq('uid', $queryBuilder->createNamedParameter($claim->uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('status', $queryBuilder->createNamedParameter(
                    [DeliveryStatus::Sending->value, DeliveryStatus::Succeeded->value],
                    Connection::PARAM_STR_ARRAY,
                )),
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * A claim that was never sent; removed only while it is still "sending".
     */
    private function removeClaim(DeliveryAttempt $claim): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->delete(
            self::TABLE,
            ['uid' => $claim->uid, 'status' => DeliveryStatus::Sending->value],
        );
    }

    protected function countOthersAfter(DeliveryAttempt $claim, int $afterUid): int
    {
        $sending = $claim;
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('report_uid', $queryBuilder->createNamedParameter($sending->reportUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('destination', $queryBuilder->createNamedParameter($sending->destination)),
                $queryBuilder->expr()->gt('uid', $queryBuilder->createNamedParameter($afterUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->neq('uid', $queryBuilder->createNamedParameter($claim->uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(DeliveryAttempt $attempt): array
    {
        $row = [
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
            'hub_receipt' => $attempt->hubReceipt?->toJson() ?? '',
        ];
        // Columns of a newer version that the database does not have yet (schema
        // not updated): the attempt is still recorded, without them
        foreach (DatabaseSchemaState::REQUIRED_COLUMNS[self::TABLE] as $column) {
            if (!$this->schema->hasColumn(self::TABLE, $column)) {
                unset($row[$column]);
            }
        }
        return $row;
    }

    private function withUid(DeliveryAttempt $attempt, int $uid): DeliveryAttempt
    {
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
            uid: $uid,
            hubReceipt: $attempt->hubReceipt,
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
     * The latest attempt of a destination with the given result, and the
     * identifier of its report (e.g. the last successful Hub delivery).
     *
     * @return array{attempt: DeliveryAttempt, reportIdentifier: string}|null
     */
    public function findLatestByDestination(string $destination, DeliveryStatus $status): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $queryBuilder
            ->select('d.*', 'r.identifier AS report_identifier')
            ->from(self::TABLE, 'd')
            ->join('d', ReportRepository::TABLE, 'r', $queryBuilder->expr()->eq('r.uid', $queryBuilder->quoteIdentifier('d.report_uid')))
            ->where(
                $queryBuilder->expr()->eq('d.destination', $queryBuilder->createNamedParameter($destination)),
                $queryBuilder->expr()->eq('d.status', $queryBuilder->createNamedParameter($status->value)),
            )
            ->orderBy('d.uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        if ($row === false) {
            return null;
        }
        return ['attempt' => $this->hydrate($row), 'reportIdentifier' => (string)$row['report_identifier']];
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
            // Read again through the strict rules of the acknowledgement
            hubReceipt: HubReceipt::fromJson((string)($row['hub_receipt'] ?? '')),
        );
    }
}
