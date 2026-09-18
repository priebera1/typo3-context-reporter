<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Controller;

use Priebera\ContextReporter\Backend\BackendLinkBuilder;
use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Context\Subject\SubjectNotAvailableException;
use Priebera\ContextReporter\Delivery\DeliveryService;
use Priebera\ContextReporter\Domain\DeliveryAttempt;
use Priebera\ContextReporter\Domain\DeliveryStatus;
use Priebera\ContextReporter\Export\ReportCopyTexts;
use Priebera\ContextReporter\Export\ReportPayloadFactory;
use Priebera\ContextReporter\Presentation\SubjectPresenter;
use Priebera\ContextReporter\Report\InvalidReportInputException;
use Priebera\ContextReporter\Report\InvalidScreenshotException;
use Priebera\ContextReporter\Report\RateLimitExceededException;
use Priebera\ContextReporter\Report\ReportInputNormalizer;
use Priebera\ContextReporter\Report\ReportService;
use Priebera\ContextReporter\Report\ReportSubmission;
use Priebera\ContextReporter\Security\InvalidDraftTokenException;
use Priebera\ContextReporter\Security\ReportAccessPolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * AJAX endpoints of the report dialog.
 *
 * "prepare" collects and seals the context the reporter reviews, "submit"
 * turns the sealed context plus the reporter's input into a stored report.
 *
 * @internal
 */
#[AsController]
final readonly class ReportAjaxController
{
    private const MAX_PREPARE_BODY_BYTES = 65536;
    private const LABELS = 'LLL:EXT:context_reporter/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private ReportService $reportService,
        private ReportAccessPolicy $accessPolicy,
        private BackendLocationFactory $locationFactory,
        private DeliveryService $deliveryService,
        private ExtensionSettingsProvider $settingsProvider,
        private UriBuilder $uriBuilder,
        private SubjectPresenter $subjectPresenter,
        private BackendLinkBuilder $links,
        private ReportPayloadFactory $payloadFactory,
        private ReportCopyTexts $copyTexts,
        private LoggerInterface $logger,
    ) {}

    public function prepareAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null || !$this->accessPolicy->canReport($backendUser)) {
            return $this->error(403, 'accessDenied');
        }
        $body = (string)$request->getBody();
        $data = strlen($body) <= self::MAX_PREPARE_BODY_BYTES ? json_decode($body, true, 16) : null;
        if (!is_array($data)) {
            return $this->error(400, 'invalidRequest');
        }

        try {
            $prepared = $this->reportService->prepare(CollectionRequest::fromArray($data, $this->locationFactory), $backendUser, $request);
        } catch (\InvalidArgumentException) {
            return $this->error(400, 'invalidRequest');
        } catch (SubjectNotAvailableException) {
            return $this->error(404, 'subjectNotAvailable');
        }

        $settings = $this->settingsProvider->get();
        $destinations = [];
        foreach ($this->deliveryService->describeEnabledDestinations() as $destination) {
            $destinations[] = array_filter([
                'identifier' => $destination->identifier,
                'label' => $this->translate('destination.' . $destination->identifier),
                // Where reports go is shown to administrators only.
                'target' => $backendUser->isAdmin() ? $destination->target : '',
            ]);
        }

        return new JsonResponse([
            'draftToken' => $prepared->draftToken,
            'source' => $prepared->source->value,
            'document' => $prepared->document->toArray(),
            'presentation' => $this->subjectPresenter->present($prepared->document)->toArray(),
            'destinations' => $destinations,
            'limits' => [
                'titleMaxLength' => ReportInputNormalizer::TITLE_MAX_LENGTH,
                'descriptionMaxLength' => ReportInputNormalizer::DESCRIPTION_MAX_LENGTH,
                'screenshotMaxBytes' => $settings->maxScreenshotBytes,
            ],
        ]);
    }

    public function submitAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null || !$this->accessPolicy->canReport($backendUser)) {
            return $this->error(403, 'accessDenied');
        }
        $body = $request->getParsedBody();
        if (!is_array($body) || $body === []) {
            // PHP drops the whole body when post_max_size is exceeded
            return (int)$request->getHeaderLine('Content-Length') > 0
                ? $this->error(413, 'requestTooLarge')
                : $this->error(400, 'invalidRequest');
        }

        $screenshot = null;
        $upload = $request->getUploadedFiles()['screenshot'] ?? null;
        if ($upload instanceof UploadedFileInterface && $upload->getError() !== UPLOAD_ERR_NO_FILE) {
            if (in_array($upload->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                || (int)$upload->getSize() > $this->settingsProvider->get()->maxScreenshotBytes
            ) {
                return $this->error(413, 'screenshot.tooLarge');
            }
            if ($upload->getError() !== UPLOAD_ERR_OK) {
                return $this->error(400, 'screenshot.uploadFailed');
            }
            $screenshot = $upload->getStream()->getContents();
        }

        $submission = new ReportSubmission(
            draftToken: is_string($body['draftToken'] ?? null) ? $body['draftToken'] : '',
            title: is_string($body['title'] ?? null) ? $body['title'] : '',
            description: is_string($body['description'] ?? null) ? $body['description'] : '',
            screenshotContent: $screenshot,
            deliver: ($body['action'] ?? 'send') !== 'download',
        );

        try {
            $result = $this->reportService->submit($submission, $backendUser);
        } catch (InvalidDraftTokenException $exception) {
            $this->logger->notice('Rejected report submission: {reason}', ['reason' => $exception->getMessage()]);
            return $this->error(400, 'draftInvalid');
        } catch (InvalidReportInputException $exception) {
            return $this->error(400, $exception->getReason());
        } catch (InvalidScreenshotException $exception) {
            return $this->error($exception->getReason() === InvalidScreenshotException::TOO_LARGE ? 413 : 400, 'screenshot.' . $exception->getReason());
        } catch (RateLimitExceededException) {
            return $this->error(429, 'rateLimited');
        }

        $report = $result->report;
        $reportUrl = $this->links->report($report->identifier);
        $deliveries = array_map(fn(DeliveryAttempt $attempt): array => [
            'destination' => $attempt->destination,
            'label' => $this->translate('destination.' . $attempt->destination),
            'successful' => $attempt->status === DeliveryStatus::Succeeded,
            'externalReference' => $attempt->externalReference,
            'externalUrl' => $attempt->externalUrl,
        ], $result->attempts);

        return new JsonResponse([
            'report' => [
                'identifier' => $report->identifier,
                'title' => $report->title,
                'createdAt' => $report->createdAt->format(\DateTimeInterface::ATOM),
                'hasScreenshot' => $report->screenshot !== null,
            ],
            'deliveryState' => $result->getDeliveryState()->value,
            'deliveries' => $deliveries,
            'downloads' => array_filter([
                'markdown' => $this->buildDownloadUrl($report->identifier, 'markdown'),
                'json' => $this->buildDownloadUrl($report->identifier, 'json'),
                'screenshot' => $report->screenshot !== null ? $this->buildDownloadUrl($report->identifier, 'screenshot', ['download' => '1']) : '',
            ]),
            'historyUrl' => $backendUser->isAdmin()
                ? (string)$this->uriBuilder->buildUriFromRoute(BackendLinkBuilder::REPORT_MODULE . '.show', ['report' => $report->identifier])
                : '',
            // The reporter reviewed this data before sending; the JSON has no screenshot content
            'copy' => $this->copyTexts->create($this->payloadFactory->create($report, $reportUrl), $reportUrl),
        ]);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function buildDownloadUrl(string $identifier, string $format, array $parameters = []): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute('context_reporter_download', ['report' => $identifier, 'format' => $format] + $parameters);
    }

    private function error(int $status, string $code): ResponseInterface
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $this->translate('error.' . $code) ?: $this->translate('error.unexpected'),
            ],
        ], $status);
    }

    private function translate(string $key): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        return $languageService instanceof LanguageService ? $languageService->sL(self::LABELS . $key) : $key;
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }
}
