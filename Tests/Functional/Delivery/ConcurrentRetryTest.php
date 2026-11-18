<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Delivery;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Backend\BackendLinkBuilder;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Database\DatabaseSchemaState;
use Priebera\ContextReporter\Delivery\DeliveryNotPossibleException;
use Priebera\ContextReporter\Delivery\DeliveryOutcome;
use Priebera\ContextReporter\Delivery\DeliveryRequest;
use Priebera\ContextReporter\Delivery\DeliveryService;
use Priebera\ContextReporter\Delivery\DestinationInterface;
use Priebera\ContextReporter\Delivery\DestinationRegistry;
use Priebera\ContextReporter\Domain\DeliveryAttempt;
use Priebera\ContextReporter\Domain\DeliveryStatus;
use Priebera\ContextReporter\Domain\Report;
use Priebera\ContextReporter\Domain\Repository\DeliveryAttemptRepository;
use Priebera\ContextReporter\Domain\Repository\ReportRepository;
use Priebera\ContextReporter\Report\ReportService;
use Priebera\ContextReporter\Report\ReportSubmission;
use Priebera\ContextReporter\Security\ConfiguredSecrets;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * A12 (audit 2026-11-07): two administrators retry the same failed delivery at
 * the same time. Both read "latest attempt failed" and both sent the email or
 * webhook. Two Fibers stand for two PHP requests on the real database; the
 * destination suspends inside its dispatch, so the second retry runs while the
 * first one is sending.
 */
final class ConcurrentRetryTest extends AbstractContextReporterTestCase
{
    /**
     * @var list<array{destination: string, attempt: int}>
     */
    private array $dispatches = [];

    #[Test]
    public function aSecondRetryWhileTheFirstIsSendingDispatchesNothing(): void
    {
        [$service, $report] = $this->failedReport();

        $first = new \Fiber(fn(): DeliveryAttempt => $service->retry($report, 'email', self::ADMIN));
        $first->start();
        self::assertTrue($first->isSuspended(), 'The first retry is sending');

        try {
            $service->retry($report, 'email', self::EDITOR);
            self::fail('The second retry must not dispatch');
        } catch (DeliveryNotPossibleException $exception) {
            self::assertSame(DeliveryNotPossibleException::IN_PROGRESS, $exception->getReason());
        }
        $first->resume();
        $attempt = $first->getReturn();

        self::assertSame([['destination' => 'email', 'attempt' => 2]], $this->dispatches, 'One dispatch');
        self::assertSame(DeliveryStatus::Succeeded, $attempt->status);
        self::assertSame(
            [['email', 1, 'failed'], ['webhook', 1, 'failed'], ['email', 2, 'succeeded']],
            $this->history($report),
        );
        // Handled: nothing left to retry
        try {
            $service->retry($report, 'email', self::EDITOR);
            self::fail('Nothing to retry');
        } catch (DeliveryNotPossibleException $exception) {
            self::assertSame(DeliveryNotPossibleException::NOTHING_TO_RETRY, $exception->getReason());
        }
    }

    #[Test]
    public function anotherDestinationIsNotBlocked(): void
    {
        [$service, $report] = $this->failedReport();

        $email = new \Fiber(fn(): DeliveryAttempt => $service->retry($report, 'email', self::ADMIN));
        $email->start();
        $webhook = $service->retry($report, 'webhook', self::ADMIN);
        $email->resume();

        self::assertSame(DeliveryStatus::Succeeded, $webhook->status);
        self::assertSame(DeliveryStatus::Succeeded, $email->getReturn()->status);
        self::assertSame([['destination' => 'email', 'attempt' => 2], ['destination' => 'webhook', 'attempt' => 2]], $this->dispatches);
    }

    /**
     * Both requests got past "latest attempt failed" before either recorded
     * anything: each records its claim, sees the other one and sends nothing
     * (a later retry is possible). Never both.
     */
    #[Test]
    public function twoRetriesThatClaimAtTheSameTimeNeverBothDispatch(): void
    {
        $attempts = new class ($this->get(ConnectionPool::class), $this->get(DatabaseSchemaState::class)) extends DeliveryAttemptRepository {
            public bool $pause = false;

            /**
             * Called after the "latest attempt failed" check and before the claim is recorded
             */
            public function countByReportAndDestination(int $reportUid, string $destination): int
            {
                if ($this->pause && \Fiber::getCurrent() !== null) {
                    \Fiber::suspend();
                }
                return parent::countByReportAndDestination($reportUid, $destination);
            }
        };
        [$service, $report] = $this->failedReport($attempts);
        $attempts->pause = true;

        $fibers = [
            new \Fiber(fn(): DeliveryAttempt => $service->retry($report, 'email', self::ADMIN)),
            new \Fiber(fn(): DeliveryAttempt => $service->retry($report, 'email', self::EDITOR)),
        ];
        // Both requests pass the check before either records its claim
        foreach ($fibers as $fiber) {
            $fiber->start();
            self::assertTrue($fiber->isSuspended());
        }
        $attempts->pause = false;
        $outcomes = [];
        foreach ($fibers as $fiber) {
            try {
                while ($fiber->isSuspended()) {
                    $fiber->resume();
                }
                $outcomes[] = $fiber->getReturn()->status->value;
            } catch (DeliveryNotPossibleException $exception) {
                $outcomes[] = $exception->getReason();
            }
        }

        self::assertLessThanOrEqual(1, count($this->dispatches), 'At most one dispatch: ' . json_encode($this->dispatches));
        self::assertSame(['succeeded', DeliveryNotPossibleException::IN_PROGRESS], $outcomes, 'The first to record its claim sends');
        self::assertSame(count($this->dispatches), count(array_filter($outcomes, static fn(string $o): bool => $o === 'succeeded')));
        if ($this->dispatches === []) {
            // Both gave way: the failed attempt is the latest again and can be retried
            self::assertSame([['email', 1, 'failed'], ['webhook', 1, 'failed']], $this->history($report));
            self::assertSame(DeliveryStatus::Succeeded, $service->retry($report, 'email', self::ADMIN)->status);
            self::assertCount(1, $this->dispatches);
        }
    }

    /**
     * Both requests recorded their claim before either looked for another one.
     * The first to look sees the other claim and gives way (its own claim is
     * removed, it sent nothing); the other one then finds only its own claim
     * and sends. Had both looked before either gave way, both would give way
     * and send nothing. Never both send.
     */
    #[Test]
    public function twoClaimsRecordedAtTheSameTimeNeverBothSend(): void
    {
        $attempts = new class ($this->get(ConnectionPool::class), $this->get(DatabaseSchemaState::class)) extends DeliveryAttemptRepository {
            public bool $pause = false;

            /**
             * After the "latest attempt failed" check, before the claim is recorded
             */
            public function countByReportAndDestination(int $reportUid, string $destination): int
            {
                if ($this->pause && \Fiber::getCurrent() !== null) {
                    \Fiber::suspend();
                }
                return parent::countByReportAndDestination($reportUid, $destination);
            }

            /**
             * After the claim is recorded, before the other claims are looked for
             */
            public function add(DeliveryAttempt $attempt): DeliveryAttempt
            {
                $added = parent::add($attempt);
                if ($this->pause && $attempt->status === DeliveryStatus::Sending && \Fiber::getCurrent() !== null) {
                    \Fiber::suspend();
                }
                return $added;
            }
        };
        [$service, $report] = $this->failedReport($attempts);
        $attempts->pause = true;
        $fibers = [
            new \Fiber(fn(): DeliveryAttempt => $service->retry($report, 'email', self::ADMIN)),
            new \Fiber(fn(): DeliveryAttempt => $service->retry($report, 'email', self::EDITOR)),
        ];
        foreach ($fibers as $fiber) {
            $fiber->start();
            self::assertTrue($fiber->isSuspended(), 'Checked, claim not yet recorded');
        }
        foreach ($fibers as $fiber) {
            $fiber->resume();
            self::assertTrue($fiber->isSuspended(), 'Claim recorded, others not yet looked for');
        }
        $attempts->pause = false;
        try {
            $fibers[0]->resume();
            self::fail('The first to look gives way');
        } catch (DeliveryNotPossibleException $exception) {
            self::assertSame(DeliveryNotPossibleException::IN_PROGRESS, $exception->getReason());
        }
        while ($fibers[1]->isSuspended()) {
            $fibers[1]->resume();
        }
        self::assertSame(DeliveryStatus::Succeeded, $fibers[1]->getReturn()->status);
        self::assertSame([['destination' => 'email', 'attempt' => 3]], $this->dispatches, 'One dispatch');
        self::assertSame([['email', 1, 'failed'], ['webhook', 1, 'failed'], ['email', 3, 'succeeded']], $this->history($report));
    }

    /**
     * The same draft was submitted twice at once (ReportService): the second
     * request found the report stored and delivers the destinations without an
     * attempt (deliverToUndeliveredDestinations) while the first request is
     * still on its way to deliverToEnabledDestinations. The first request must
     * not send to a destination the second one is already sending to.
     */
    #[Test]
    public function theFirstDeliveryDoesNotSendWhatAParallelSubmissionIsAlreadySending(): void
    {
        [$service, $report] = $this->failedReport(withFailedAttempts: false);

        $replay = new \Fiber(fn(): array => $service->deliverToUndeliveredDestinations($report, self::EDITOR));
        $replay->start();
        self::assertTrue($replay->isSuspended(), 'The parallel submission is sending the email');

        $first = $service->deliverToEnabledDestinations($report, self::EDITOR);
        $this->finish($replay);

        self::assertSame(
            [['destination' => 'email', 'attempt' => 1], ['destination' => 'webhook', 'attempt' => 1]],
            $this->dispatches,
            'Every destination once: ' . json_encode($this->dispatches),
        );
        self::assertSame([['email', 1, 'succeeded'], ['webhook', 1, 'succeeded']], $this->history($report));
        self::assertSame(['email', 'webhook'], array_map(static fn(DeliveryAttempt $a): string => $a->destination, $first));
    }

    /**
     * Both submissions of the same draft decided to deliver the email before
     * either recorded its claim, and both looked for the other claim before
     * either gave way: both give way and nothing is sent. The email must not
     * vanish from the history: it stays as a failed attempt that can be
     * retried, and the retry sends it once.
     */
    #[Test]
    public function twoFirstDeliveriesThatBothGiveWayLeaveAnAttemptThatCanBeRetried(): void
    {
        $attempts = new class ($this->get(ConnectionPool::class), $this->get(DatabaseSchemaState::class)) extends DeliveryAttemptRepository {
            public bool $pause = false;

            /**
             * After the decision to send, before the claim is recorded
             */
            public function countByReportAndDestination(int $reportUid, string $destination): int
            {
                if ($this->pause && $destination === 'email' && \Fiber::getCurrent() !== null) {
                    \Fiber::suspend();
                }
                return parent::countByReportAndDestination($reportUid, $destination);
            }

            /**
             * After the claim is recorded, before the other claims are looked for
             */
            public function add(DeliveryAttempt $attempt): DeliveryAttempt
            {
                $added = parent::add($attempt);
                if ($this->pause && $attempt->status === DeliveryStatus::Sending && $attempt->destination === 'email' && \Fiber::getCurrent() !== null) {
                    \Fiber::suspend();
                }
                return $added;
            }

            /**
             * After the other claims were looked for, before giving way
             */
            protected function countOthersAfter(DeliveryAttempt $claim, int $afterUid): int
            {
                $others = parent::countOthersAfter($claim, $afterUid);
                if ($this->pause && $claim->destination === 'email' && \Fiber::getCurrent() !== null) {
                    \Fiber::suspend();
                }
                return $others;
            }
        };
        [$service, $report] = $this->failedReport($attempts, withFailedAttempts: false);
        $attempts->pause = true;
        $fibers = [
            new \Fiber(fn(): array => $service->deliverToEnabledDestinations($report, self::EDITOR)),
            new \Fiber(fn(): array => $service->deliverToUndeliveredDestinations($report, self::EDITOR)),
        ];
        // Both decide to send the email, then both record a claim, then both look for the other claim
        foreach ($fibers as $fiber) {
            $fiber->start();
        }
        for ($step = 0; $step < 2; $step++) {
            foreach ($fibers as $fiber) {
                self::assertTrue($fiber->isSuspended());
                $fiber->resume();
            }
        }
        $attempts->pause = false;
        foreach ($fibers as $fiber) {
            while ($fiber->isSuspended()) {
                $fiber->resume();
            }
        }

        self::assertSame([['destination' => 'webhook', 'attempt' => 1]], $this->dispatches, 'The email was not sent, the webhook once');
        $email = $service->findLatestAttempts($report)['email'] ?? null;
        self::assertNotNull($email, 'The email delivery is recorded');
        self::assertSame(DeliveryStatus::Failed, $email->status);
        self::assertSame(DeliveryService::CONCURRENT_NOT_SENT_MESSAGE, $email->message);
        self::assertTrue($service->canRetry($email));
        $retried = $service->retry($report, 'email', self::ADMIN);
        self::assertSame(DeliveryStatus::Succeeded, $retried->status);
        self::assertSame([['destination' => 'webhook', 'attempt' => 1], ['destination' => 'email', 'attempt' => 2]], $this->dispatches);
    }

    /**
     * The claim was recorded but could not be checked (the database went away):
     * nothing is sent, the claim is removed (it must not read "may have been
     * sent" later) and the failed attempt stays retryable.
     */
    #[Test]
    public function aClaimThatCannotBeCheckedIsRemovedAndNothingIsSent(): void
    {
        $attempts = new class ($this->get(ConnectionPool::class), $this->get(DatabaseSchemaState::class)) extends DeliveryAttemptRepository {
            public bool $failCheck = false;

            protected function countOthersAfter(DeliveryAttempt $claim, int $afterUid): int
            {
                if ($this->failCheck) {
                    throw new \RuntimeException('Connection lost');
                }
                return parent::countOthersAfter($claim, $afterUid);
            }
        };
        [$service, $report] = $this->failedReport($attempts);
        $attempts->failCheck = true;

        $attempt = $service->retry($report, 'email', self::ADMIN);

        self::assertSame(DeliveryStatus::Failed, $attempt->status);
        self::assertSame(DeliveryService::NOT_RECORDED_MESSAGE, $attempt->message);
        self::assertSame([], $this->dispatches);
        self::assertSame([['email', 1, 'failed'], ['webhook', 1, 'failed']], $this->history($report));
        $attempts->failCheck = false;
        self::assertSame(DeliveryStatus::Succeeded, $service->retry($report, 'email', self::ADMIN)->status);
        self::assertCount(1, $this->dispatches);
    }

    /**
     * The process ended after the destination accepted the delivery, before
     * its outcome was recorded. The claim stays "sending": no retry while it
     * may still run, and afterwards only a manual one, with a history entry
     * that says the delivery may have been sent.
     */
    #[Test]
    public function anUnrecordedOutcomeIsNeverRetriedBlindly(): void
    {
        $attempts = new class ($this->get(ConnectionPool::class), $this->get(DatabaseSchemaState::class)) extends DeliveryAttemptRepository {
            public bool $failCompletion = false;

            public function complete(DeliveryAttempt $attempt): bool
            {
                if ($this->failCompletion) {
                    throw new \RuntimeException('Database gone after the dispatch');
                }
                return parent::complete($attempt);
            }
        };
        [$service, $report] = $this->failedReport($attempts);
        $attempts->failCompletion = true;

        $service->retry($report, 'email', self::ADMIN);
        $attempts->failCompletion = false;

        self::assertCount(1, $this->dispatches);
        $latest = $service->findLatestAttempts($report)['email'];
        self::assertSame(DeliveryStatus::Sending, $latest->status);
        self::assertStringContainsString('may have been sent', $latest->message);
        self::assertFalse($service->canRetry($latest));
        try {
            $service->retry($report, 'email', self::ADMIN);
            self::fail('No retry while the outcome may still arrive');
        } catch (DeliveryNotPossibleException $exception) {
            self::assertSame(DeliveryNotPossibleException::IN_PROGRESS, $exception->getReason());
        }
        self::assertCount(1, $this->dispatches);

        // Long after any time limit: the outcome is unknown, a person may retry
        $this->get(ConnectionPool::class)->getConnectionForTable(DeliveryAttemptRepository::TABLE)->update(
            DeliveryAttemptRepository::TABLE,
            ['crdate' => time() - DeliveryService::OUTCOME_UNKNOWN_AFTER_SECONDS - 1],
            ['uid' => $latest->uid],
        );
        $stale = $service->findLatestAttempts($report)['email'];
        self::assertTrue($service->canRetry($stale));
        self::assertSame(DeliveryStatus::Succeeded, $service->retry($report, 'email', self::ADMIN)->status);
        self::assertCount(2, $this->dispatches);
        self::assertSame([['email', 1, 'failed'], ['webhook', 1, 'failed'], ['email', 2, 'sending'], ['email', 3, 'succeeded']], $this->history($report));
    }

    /**
     * A report whose email and webhook deliveries failed, and a DeliveryService
     * whose destinations record their dispatches (and suspend inside a Fiber).
     *
     * @return array{DeliveryService, Report}
     */
    private function failedReport(?DeliveryAttemptRepository $attempts = null, bool $withFailedAttempts = true): array
    {
        $editor = $this->loginBackendUser(self::EDITOR);
        $this->configureExtension([]);
        $request = CollectionRequest::fromArray(['source' => 'contextMenu', 'target' => ['type' => 'page', 'uid' => 2]], new BackendLocationFactory());
        $reportService = $this->get(ReportService::class);
        $prepared = $reportService->prepare($request, $editor, $GLOBALS['TYPO3_REQUEST']);
        $report = $reportService->submit(new ReportSubmission($prepared->draftToken, 'Teaser is broken', '', null, false), $editor)->report;
        $attempts ??= $this->get(DeliveryAttemptRepository::class);
        foreach ($withFailedAttempts ? ['email', 'webhook'] : [] as $destination) {
            $attempts->add(new DeliveryAttempt((int)$report->uid, $destination, 1, DeliveryStatus::Failed, new \DateTimeImmutable(), 'target', self::EDITOR, message: 'Timeout'));
        }
        $dispatches = &$this->dispatches;
        $destination = static function (string $identifier) use (&$dispatches): DestinationInterface {
            return new class ($identifier, $dispatches) implements DestinationInterface {
                /**
                 * @param list<array{destination: string, attempt: int}> $dispatches
                 */
                public function __construct(private string $identifier, private array &$dispatches) {}

                public function getIdentifier(): string
                {
                    return $this->identifier;
                }

                public function dispatched(): int
                {
                    return count($this->dispatches);
                }

                public function isEnabled(): bool
                {
                    return true;
                }

                public function describeTarget(): string
                {
                    return 'synthetic ' . $this->identifier;
                }

                public function deliver(DeliveryRequest $request): DeliveryOutcome
                {
                    $this->dispatches[] = ['destination' => $this->identifier, 'attempt' => $request->attempt];
                    if (\Fiber::getCurrent() !== null) {
                        \Fiber::suspend();
                    }
                    return DeliveryOutcome::success('OK', 200);
                }
            };
        };
        $service = new DeliveryService(
            new DestinationRegistry([$destination('email'), $destination('webhook')]),
            $attempts,
            $this->get(ReportRepository::class),
            $this->get(BackendLinkBuilder::class),
            new NullLogger(),
            $this->get(ConfiguredSecrets::class),
            $this->get(DatabaseSchemaState::class),
        );
        return [$service, $report];
    }

    /**
     * Lets a suspended request run to its end (it suspends again in every dispatch).
     *
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    private function finish(\Fiber $fiber): void
    {
        while ($fiber->isSuspended()) {
            $fiber->resume();
        }
    }

    /**
     * @return list<array{string, int, string}>
     */
    private function history(Report $report): array
    {
        return array_values(array_map(
            static fn(DeliveryAttempt $a): array => [$a->destination, $a->attempt, $a->status->value],
            $this->get(DeliveryAttemptRepository::class)->findByReportUid((int)$report->uid),
        ));
    }
}
