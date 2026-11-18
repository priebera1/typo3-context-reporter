<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Report;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\ContextDocumentBuilder;
use Priebera\ContextReporter\Context\Subject\SubjectNotAvailableException;
use Priebera\ContextReporter\Delivery\DeliveryService;
use Priebera\ContextReporter\Domain\AttachmentMetadata;
use Priebera\ContextReporter\Domain\ContextDocument;
use Priebera\ContextReporter\Domain\Report;
use Priebera\ContextReporter\Domain\ReportSource;
use Priebera\ContextReporter\Domain\Repository\ReportRepository;
use Priebera\ContextReporter\Security\DraftTokenService;
use Priebera\ContextReporter\Security\InvalidDraftTokenException;
use Priebera\ContextReporter\Security\ReportAccessPolicy;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Application service for the two steps of reporting: preparing the reviewed
 * context, and creating, storing and delivering the report.
 *
 * Submitting is idempotent per prepared report: prepare() reserves the report
 * identifier in the signed draft, so a submission repeated after an
 * interrupted answer (the report was stored, the browser never heard of it)
 * returns the stored report instead of creating and delivering a second one.
 * The draft is bound to its user, and a report of another user is never
 * returned (an identifier taken by another report gets a fresh one).
 *
 * @internal
 */
final readonly class ReportService
{
    private const RATE_LIMIT_WINDOW_SECONDS = 3600;
    private const IDENTIFIER_ATTEMPTS = 3;

    public function __construct(
        private ContextDocumentBuilder $documentBuilder,
        private DraftTokenService $draftTokens,
        private ExtensionSettingsProvider $settingsProvider,
        private ScreenshotValidator $screenshotValidator,
        private ReportIdGenerator $idGenerator,
        private ReportRepository $reports,
        private DeliveryService $deliveryService,
    ) {}

    /**
     * @throws SubjectNotAvailableException
     */
    public function prepare(CollectionRequest $request, BackendUserAuthentication $backendUser, ServerRequestInterface $httpRequest): PreparedReport
    {
        $document = $this->documentBuilder->build($request, $backendUser, $httpRequest);
        $identifier = $this->idGenerator->generate();
        $token = $this->draftTokens->issue(
            ['source' => $request->source->value, 'document' => $document->toArray(), 'report' => $identifier],
            ReportAccessPolicy::getUserId($backendUser),
        );
        return new PreparedReport($request->source, $document, $token, $identifier);
    }

    /**
     * @throws InvalidDraftTokenException
     * @throws InvalidReportInputException
     * @throws InvalidScreenshotException
     * @throws RateLimitExceededException
     */
    public function submit(ReportSubmission $submission, BackendUserAuthentication $backendUser): SubmissionResult
    {
        $userId = ReportAccessPolicy::getUserId($backendUser);
        $settings = $this->settingsProvider->get();

        $draft = $this->draftTokens->verify($submission->draftToken, $userId);
        $reserved = is_string($draft['report'] ?? null) && ReportIdGenerator::isValid($draft['report']) ? $draft['report'] : '';
        $stored = $this->findOwnReport($reserved, $userId);
        if ($stored !== null) {
            // Submitted before: the answer did not reach the browser (or the request ended before delivering)
            return $this->replay($stored, $submission->deliver, $userId);
        }
        $title = ReportInputNormalizer::title($submission->title);
        $description = ReportInputNormalizer::description($submission->description);
        $screenshot = $submission->screenshotContent !== null
            ? $this->screenshotValidator->validate($submission->screenshotContent, $settings->maxScreenshotBytes)
            : null;
        if ($settings->maxReportsPerUserPerHour > 0
            && $this->reports->countByReporterSince($userId, time() - self::RATE_LIMIT_WINDOW_SECONDS) >= $settings->maxReportsPerUserPerHour
        ) {
            throw new RateLimitExceededException('Too many reports in a short time.', 1757930701);
        }

        $document = ContextDocument::fromArray(is_array($draft['document'] ?? null) ? $draft['document'] : []);
        $source = ReportSource::tryFrom(is_string($draft['source'] ?? null) ? $draft['source'] : '') ?? ReportSource::Toolbar;

        [$report, $created] = $this->store($reserved, $userId, $source, $title, $description, $document, $screenshot);
        if (!$created) {
            // The same draft was submitted twice at the same time
            return $this->replay($report, $submission->deliver, $userId);
        }
        $attempts = $submission->deliver ? $this->deliveryService->deliverToEnabledDestinations($report, $userId) : [];
        return new SubmissionResult($report, $attempts);
    }

    private function findOwnReport(string $identifier, int $userId): ?Report
    {
        $report = $identifier !== '' ? $this->reports->findByIdentifier($identifier) : null;
        return $report !== null && $report->reporterUid === $userId ? $report : null;
    }

    /**
     * The stored report and the latest delivery of each destination, without
     * storing it again or delivering it again to a destination that has an
     * attempt. A destination without any attempt (the first request ended
     * between storing and delivering) is delivered now, once.
     */
    private function replay(Report $report, bool $deliver, int $userId): SubmissionResult
    {
        $attempts = $deliver
            ? $this->deliveryService->deliverToUndeliveredDestinations($report, $userId)
            : array_values($this->deliveryService->findLatestAttempts($report));
        return new SubmissionResult($report, $attempts, replayed: true);
    }

    /**
     * Stores the report under the identifier reserved in the draft, or under a
     * new one when the draft has none or another report has it.
     *
     * @return array{Report, bool} The report, and false when it was stored by a parallel submission of the same draft
     */
    private function store(
        string $reserved,
        int $userId,
        ReportSource $source,
        string $title,
        string $description,
        ContextDocument $document,
        ?Screenshot $screenshot,
    ): array {
        for ($attempt = 1; ; $attempt++) {
            $identifier = $attempt === 1 && $reserved !== '' ? $reserved : $this->idGenerator->generate();
            $report = new Report(
                identifier: $identifier,
                createdAt: new \DateTimeImmutable(),
                reporterUid: $userId,
                source: $source,
                title: $title,
                description: $description,
                document: $document,
                screenshot: $screenshot !== null ? AttachmentMetadata::fromScreenshot($screenshot, $identifier) : null,
            );
            try {
                return [$this->reports->add($report, $screenshot), true];
            } catch (UniqueConstraintViolationException $exception) {
                $stored = $identifier === $reserved ? $this->findOwnReport($reserved, $userId) : null;
                if ($stored !== null) {
                    return [$stored, false];
                }
                if ($attempt >= self::IDENTIFIER_ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }
}
