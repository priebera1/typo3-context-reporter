<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery;

use Priebera\ContextReporter\Backend\BackendLinkBuilder;
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
    ) {}

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
        foreach ($this->destinations->getEnabled() as $destination) {
            $attempts[] = $this->deliver($report, $destination, $triggeredBy);
        }
        $this->refreshDeliveryState($report);
        return $attempts;
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
        if ($latest === null || $latest->status !== DeliveryStatus::Failed) {
            throw DeliveryNotPossibleException::create(DeliveryNotPossibleException::NOTHING_TO_RETRY);
        }
        $attempt = $this->deliver($report, $destination, $triggeredBy);
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
            && $attempt->status === DeliveryStatus::Failed
            && ($this->destinations->get($attempt->destination)?->isEnabled() ?? false);
    }

    private function deliver(Report $report, DestinationInterface $destination, int $triggeredBy): DeliveryAttempt
    {
        $attemptNumber = $this->attempts->countByReportAndDestination($report->uid, $destination->getIdentifier()) + 1;
        $request = new DeliveryRequest(
            report: $report,
            reportUrl: $this->links->report($report->identifier),
            attempt: $attemptNumber,
            deliveryId: DeliveryId::create(),
            screenshotLoader: fn(): ?string => $this->reports->findScreenshotContent($report->uid),
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

        return $this->attempts->add(new DeliveryAttempt(
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
