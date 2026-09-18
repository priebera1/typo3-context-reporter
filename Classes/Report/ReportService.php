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
        $token = $this->draftTokens->issue(
            ['source' => $request->source->value, 'document' => $document->toArray()],
            ReportAccessPolicy::getUserId($backendUser),
        );
        return new PreparedReport($request->source, $document, $token);
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

        $report = $this->store($userId, $source, $title, $description, $document, $screenshot);
        $attempts = $submission->deliver ? $this->deliveryService->deliverToEnabledDestinations($report, $userId) : [];
        return new SubmissionResult($report, $attempts);
    }

    private function store(
        int $userId,
        ReportSource $source,
        string $title,
        string $description,
        ContextDocument $document,
        ?Screenshot $screenshot,
    ): Report {
        for ($attempt = 1; ; $attempt++) {
            $identifier = $this->idGenerator->generate();
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
                return $this->reports->add($report, $screenshot);
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= self::IDENTIFIER_ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }
}
