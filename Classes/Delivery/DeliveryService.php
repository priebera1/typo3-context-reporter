<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery;

use Priebera\ContextReporter\Backend\BackendLinkBuilder;
use Priebera\ContextReporter\Database\DatabaseSchemaState;
use Priebera\ContextReporter\Domain\DeliveryAttempt;
use Priebera\ContextReporter\Domain\DeliveryState;
use Priebera\ContextReporter\Domain\DeliveryStatus;
use Priebera\ContextReporter\Domain\Report;
use Priebera\ContextReporter\Domain\Repository\DeliveryAttemptRepository;
use Priebera\ContextReporter\Domain\Repository\ReportRepository;
use Priebera\ContextReporter\Security\ConfiguredSecrets;
use Psr\Log\LoggerInterface;

/**
 * Pushes reports to the enabled destinations and records every attempt.
 * Delivery is synchronous; there is no local queue. Failed deliveries can be
 * retried manually from the report history.
 *
 * The report is stored before anything is delivered. Destinations run one
 * after another in the order of their priority (Hub, email, webhook), each on
 * its own: a failure of one never stops the others. A destination sees the
 * latest earlier attempt of every destination (the email names the Hub
 * reference once the Hub acknowledged the report).
 *
 * Nothing leaves the installation while the database misses columns of this
 * version: every enabled destination gets a failed attempt that says so, and
 * the retry after the schema update delivers exactly once.
 *
 * Every dispatch is claimed first: an attempt "sending" is recorded before
 * the destination is called (DeliveryAttemptRepository::claim(), no database
 * transaction is held while sending), and the outcome is written to it
 * afterwards. A second request that retries the same failed attempt (two
 * administrators, a double click) finds the claim and sends nothing
 * (DeliveryNotPossibleException::IN_PROGRESS); when two requests give way to
 * each other, a failed attempt that can be retried is left, so a destination
 * never disappears from the history. An attempt still "sending"
 * after OUTCOME_UNKNOWN_AFTER_SECONDS lost its outcome (the process ended
 * while or right after sending): it may have been sent, so it is never
 * retried automatically; the history says so and a person may retry it.
 *
 * @internal
 */
final readonly class DeliveryService
{
    public function __construct(
        private DestinationRegistry $destinations,
        private DeliveryAttemptRepository $attempts,
        private ReportRepository $reports,
        private BackendLinkBuilder $links,
        private LoggerInterface $logger,
        private ConfiguredSecrets $secrets,
        private DatabaseSchemaState $schema,
    ) {}

    /**
     * Text of the attempt recorded instead of a delivery (stored in English like the report).
     */
    /**
     * Far beyond every time limit of a dispatch (Hub 5 s, webhook and mail
     * timeouts): a claim this old is no longer being sent.
     */
    public const OUTCOME_UNKNOWN_AFTER_SECONDS = 600;

    /**
     * Text of a claimed attempt until its outcome is recorded (stored in English like the report).
     */
    public const SENDING_MESSAGE = 'Sending. If this does not change, the delivery was interrupted before its outcome was known: it may have been sent. Check the destination before you retry.';

    /**
     * Text of an attempt that was not sent because it could not be recorded first.
     */
    public const NOT_RECORDED_MESSAGE = 'Not sent: the delivery could not be recorded in the database. See the TYPO3 log for details.';

    /**
     * Text of an attempt that was not sent because two requests (the same report submitted twice at once, or two
     * retries) wanted to send it at the same time and both gave way (DeliveryAttemptRepository::claim()).
     */
    public const CONCURRENT_NOT_SENT_MESSAGE = 'Not sent: the report was being sent twice at the same time and neither request sent it. Retry the delivery.';

    public const SCHEMA_OUTDATED_MESSAGE = 'Not sent: the database tables of Context Reporter need an update. Run "Analyze Database Structure" in Admin Tools › Maintenance (or vendor/bin/typo3 extension:setup), then retry.';

    /**
     * @return list<DestinationSummary>
     */
    public function describeEnabledDestinations(): array
    {
        return array_map(
            static fn(DestinationInterface $destination): DestinationSummary => new DestinationSummary(
                $destination->getIdentifier(),
                $destination->describeTarget(),
            ),
            $this->destinations->getEnabled(),
        );
    }

    /**
     * @return list<DeliveryAttempt>
     */
    public function deliverToEnabledDestinations(Report $report, int $triggeredBy): array
    {
        $attempts = [];
        $schemaCurrent = $this->schema->isCurrent();
        foreach ($this->destinations->getEnabled() as $destination) {
            // The first delivery of a new report follows no attempt: an attempt that exists already was recorded by
            // a parallel submission of the same draft (deliverToUndeliveredDestinations), and its claim makes this
            // one give way instead of sending the destination a second time
            $attempt = $schemaCurrent
                ? $this->deliver($report, $destination, $triggeredBy, null)
                : $this->recordNotSent($report, $destination, $triggeredBy);
            // null: another request is delivering the report to this destination
            $attempt ??= $this->findLatestAttempts($report)[$destination->getIdentifier()] ?? null;
            if ($attempt !== null) {
                $attempts[] = $attempt;
            }
        }
        $this->refreshDeliveryState($report);
        return $attempts;
    }

    /**
     * Delivers to the enabled destinations that have no attempt for the report
     * yet: a submission repeated after its request ended between storing and
     * delivering (the PHP process died). A destination another request is
     * delivering to already has its claim, and a claim made at the same time
     * gives way (DeliveryAttemptRepository::claim()), so nothing is sent twice.
     *
     * @return list<DeliveryAttempt> The latest attempt of every destination
     */
    public function deliverToUndeliveredDestinations(Report $report, int $triggeredBy): array
    {
        $latest = $this->findLatestAttempts($report);
        $missing = array_filter(
            $this->destinations->getEnabled(),
            static fn(DestinationInterface $destination): bool => !isset($latest[$destination->getIdentifier()]),
        );
        if ($missing !== []) {
            $schemaCurrent = $this->schema->isCurrent();
            foreach ($missing as $destination) {
                $schemaCurrent
                    ? $this->deliver($report, $destination, $triggeredBy, null)
                    : $this->recordNotSent($report, $destination, $triggeredBy);
            }
            $this->refreshDeliveryState($report);
        }
        return array_values($this->findLatestAttempts($report));
    }

    /**
     * @throws DeliveryNotPossibleException
     */
    public function retry(Report $report, string $destinationIdentifier, int $triggeredBy): DeliveryAttempt
    {
        $destination = $this->destinations->get($destinationIdentifier);
        if ($destination === null || !$destination->isEnabled()) {
            throw DeliveryNotPossibleException::create(DeliveryNotPossibleException::DESTINATION_UNAVAILABLE);
        }
        $latest = $this->findLatestAttempts($report)[$destinationIdentifier] ?? null;
        if ($latest !== null && $latest->status === DeliveryStatus::Sending && !$this->isOutcomeUnknown($latest)) {
            throw DeliveryNotPossibleException::create(DeliveryNotPossibleException::IN_PROGRESS);
        }
        if ($latest === null || !$this->isRetryable($latest)) {
            throw DeliveryNotPossibleException::create(DeliveryNotPossibleException::NOTHING_TO_RETRY);
        }
        if (!$this->schema->isCurrent()) {
            throw DeliveryNotPossibleException::create(DeliveryNotPossibleException::SCHEMA_OUTDATED);
        }
        $attempt = $this->deliver($report, $destination, $triggeredBy, $latest);
        if ($attempt === null) {
            // Another request claimed this retry first
            throw DeliveryNotPossibleException::create(DeliveryNotPossibleException::IN_PROGRESS);
        }
        $this->refreshDeliveryState($report);
        return $attempt;
    }

    public function recordDownload(Report $report, int $triggeredBy, string $format): DeliveryAttempt
    {
        return $this->attempts->add(new DeliveryAttempt(
            reportUid: $report->uid,
            destination: DeliveryAttempt::DESTINATION_DOWNLOAD,
            attempt: $this->attempts->countByReportAndDestination($report->uid, DeliveryAttempt::DESTINATION_DOWNLOAD) + 1,
            status: DeliveryStatus::Succeeded,
            createdAt: new \DateTimeImmutable(),
            target: $format,
            triggeredBy: $triggeredBy,
        ));
    }

    /**
     * Latest push delivery attempt per destination, downloads excluded.
     *
     * @return array<string, DeliveryAttempt>
     */
    public function findLatestAttempts(Report $report): array
    {
        $latest = [];
        foreach ($this->attempts->findByReportUid($report->uid) as $attempt) {
            if (!$attempt->isDownload()) {
                $latest[$attempt->destination] = $attempt;
            }
        }
        return $latest;
    }

    public function canRetry(DeliveryAttempt $attempt): bool
    {
        return !$attempt->isDownload()
            && $this->isRetryable($attempt)
            && ($this->destinations->get($attempt->destination)?->isEnabled() ?? false);
    }

    /**
     * Still "sending" long after any dispatch would have ended: the outcome is unknown.
     */
    public function isOutcomeUnknown(DeliveryAttempt $attempt): bool
    {
        return $attempt->status === DeliveryStatus::Sending
            && $attempt->createdAt->getTimestamp() < time() - self::OUTCOME_UNKNOWN_AFTER_SECONDS;
    }

    /**
     * A failed attempt, or one whose outcome is unknown (retried only by a person, never automatically).
     */
    private function isRetryable(DeliveryAttempt $attempt): bool
    {
        return $attempt->status === DeliveryStatus::Failed || $this->isOutcomeUnknown($attempt);
    }

    /**
     * Claims the dispatch, sends and records the outcome on the claim.
     *
     * @param DeliveryAttempt|null $after The latest attempt of the destination when the caller decided to send
     * @return DeliveryAttempt|null null when another request claimed the dispatch first (nothing was sent)
     */
    private function deliver(Report $report, DestinationInterface $destination, int $triggeredBy, ?DeliveryAttempt $after): ?DeliveryAttempt
    {
        $attemptNumber = $this->attempts->countByReportAndDestination($report->uid, $destination->getIdentifier()) + 1;
        $sending = new DeliveryAttempt(
            reportUid: $report->uid,
            destination: $destination->getIdentifier(),
            attempt: $attemptNumber,
            status: DeliveryStatus::Sending,
            createdAt: new \DateTimeImmutable(),
            target: $destination->describeTarget(),
            triggeredBy: $triggeredBy,
            message: self::SENDING_MESSAGE,
        );
        try {
            $claim = $this->attempts->claim($sending, $after === null ? 0 : $after->uid, self::CONCURRENT_NOT_SENT_MESSAGE);
        } catch (\Throwable $exception) {
            // Without a record of the claim, sending could not be told apart from not sending: nothing is sent
            $this->logger->error(
                'Delivery of report {report} to {destination} was not sent: its claim could not be recorded: {error} ({location})',
                ['report' => $report->identifier, 'destination' => $destination->getIdentifier()]
                    + SafeErrorMessage::logContext($exception, $this->secrets->get()),
            );
            $this->schema->reset();
            return new DeliveryAttempt(
                reportUid: $report->uid,
                destination: $destination->getIdentifier(),
                attempt: $attemptNumber,
                status: DeliveryStatus::Failed,
                createdAt: new \DateTimeImmutable(),
                target: $destination->describeTarget(),
                triggeredBy: $triggeredBy,
                message: self::NOT_RECORDED_MESSAGE,
            );
        }
        if ($claim === null) {
            return null;
        }
        if ($claim->status === DeliveryStatus::Failed) {
            // Every request that wanted to send gave way: nothing was sent, the failed attempt can be retried
            return $claim;
        }
        $request = new DeliveryRequest(
            report: $report,
            reportUrl: $this->links->report($report->identifier),
            attempt: $attemptNumber,
            deliveryId: DeliveryId::create(),
            screenshotLoader: fn(): ?string => $this->reports->findScreenshotContent($report->uid),
            priorAttempts: $this->findLatestAttempts($report),
        );

        $start = hrtime(true);
        try {
            $outcome = $destination->deliver($request);
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Unexpected error while delivering report {report} to {destination}: {error} ({location})',
                ['report' => $report->identifier, 'destination' => $destination->getIdentifier()]
                    + SafeErrorMessage::logContext($exception, $this->secrets->get()),
            );
            $outcome = DeliveryOutcome::failure('Unexpected error. See the TYPO3 log for details.');
        }
        $durationMs = (int)round((hrtime(true) - $start) / 1_000_000);

        if (!$outcome->successful) {
            $this->logger->warning('Delivery of report {report} to {destination} failed: {message}', [
                'report' => $report->identifier,
                'destination' => $destination->getIdentifier(),
                'message' => $outcome->message,
            ]);
        }

        $attempt = new DeliveryAttempt(
            reportUid: $report->uid,
            destination: $destination->getIdentifier(),
            attempt: $attemptNumber,
            status: $outcome->successful ? DeliveryStatus::Succeeded : DeliveryStatus::Failed,
            createdAt: new \DateTimeImmutable(),
            target: $destination->describeTarget(),
            triggeredBy: $triggeredBy,
            responseCode: $outcome->responseCode,
            message: $outcome->message,
            externalReference: $outcome->externalReference,
            externalUrl: $outcome->externalUrl,
            durationMs: $durationMs,
            uid: $claim->uid,
            hubReceipt: $outcome->hubReceipt,
        );
        try {
            if (!$this->attempts->complete($attempt)) {
                // The claim was given up meanwhile (outcome unknown, retried by a person): its row is not overwritten
                $this->logger->warning('Delivery of report {report} to {destination} ended after its claim was given up: {status}', [
                    'report' => $report->identifier,
                    'destination' => $destination->getIdentifier(),
                    'status' => $attempt->status->value,
                ]);
            }
            return $attempt;
        } catch (\Throwable $exception) {
            // The destination may have the report already: the reporter must not get an error page for it. Read the
            // columns again and record once more; otherwise the claim stays "sending" (outcome unknown), which is
            // never retried automatically.
            $this->logger->error(
                'Delivery of report {report} to {destination} could not be recorded: {error} ({location})',
                ['report' => $report->identifier, 'destination' => $destination->getIdentifier()]
                    + SafeErrorMessage::logContext($exception, $this->secrets->get()),
            );
            $this->schema->reset();
            try {
                $this->attempts->complete($attempt);
            } catch (\Throwable) {
                // Kept as "sending"
            }
            return $attempt;
        }
    }

    private function recordNotSent(Report $report, DestinationInterface $destination, int $triggeredBy): DeliveryAttempt
    {
        $this->logger->warning('Report {report} was not sent to {destination}: database schema update required ({columns})', [
            'report' => $report->identifier,
            'destination' => $destination->getIdentifier(),
            'columns' => implode(', ', $this->schema->getMissingColumns()),
        ]);
        return $this->attempts->add(new DeliveryAttempt(
            reportUid: $report->uid,
            destination: $destination->getIdentifier(),
            attempt: $this->attempts->countByReportAndDestination($report->uid, $destination->getIdentifier()) + 1,
            status: DeliveryStatus::Failed,
            createdAt: new \DateTimeImmutable(),
            target: $destination->describeTarget(),
            triggeredBy: $triggeredBy,
            message: self::SCHEMA_OUTDATED_MESSAGE,
        ));
    }

    private function refreshDeliveryState(Report $report): void
    {
        $statuses = array_map(
            static fn(DeliveryAttempt $attempt): DeliveryStatus => $attempt->status,
            $this->findLatestAttempts($report),
        );
        $this->reports->updateDeliveryState($report->uid, DeliveryState::fromLatestStatuses($statuses));
    }
}
