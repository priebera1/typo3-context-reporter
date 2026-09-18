<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Controller;

use Priebera\ContextReporter\Backend\BackendLinkBuilder;
use Priebera\ContextReporter\Backend\BackendUserNames;
use Priebera\ContextReporter\Context\ReportTarget;
use Priebera\ContextReporter\Delivery\DeliveryNotPossibleException;
use Priebera\ContextReporter\Delivery\DeliveryService;
use Priebera\ContextReporter\Delivery\SafeErrorMessage;
use Priebera\ContextReporter\Delivery\Webhook\ExternalReferenceSanitizer;
use Priebera\ContextReporter\Domain\DeliveryAttempt;
use Priebera\ContextReporter\Domain\DeliveryState;
use Priebera\ContextReporter\Domain\DeliveryStatus;
use Priebera\ContextReporter\Domain\Report;
use Priebera\ContextReporter\Domain\Repository\DeliveryAttemptRepository;
use Priebera\ContextReporter\Domain\Repository\ReportRepository;
use Priebera\ContextReporter\Domain\ReviewState;
use Priebera\ContextReporter\Domain\SubjectType;
use Priebera\ContextReporter\Export\ContextDetailsFormatter;
use Priebera\ContextReporter\Export\MarkdownReportExporter;
use Priebera\ContextReporter\Export\ReportCopyTexts;
use Priebera\ContextReporter\Export\ReportPayloadFactory;
use Priebera\ContextReporter\Presentation\SubjectPresenter;
use Priebera\ContextReporter\Report\ReportIdGenerator;
use Priebera\ContextReporter\Security\ConfiguredSecrets;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownItem;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * System > Context Reports: report history and delivery audit.
 * This is deliberately not a ticket system: reports cannot be edited,
 * assigned or commented on. Administrators can only mark a report as
 * resolved (and reopen it) to keep track of what has been dealt with.
 *
 * @internal
 */
#[AsController]
final readonly class ReportModuleController
{
    use ModuleControllerTrait;

    public const MODULE = BackendLinkBuilder::REPORT_MODULE;
    private const ITEMS_PER_PAGE = 25;
    private const REVIEW_FILTER_ALL = 'all';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ReportRepository $reports,
        private DeliveryAttemptRepository $attempts,
        private DeliveryService $deliveryService,
        private ContextDetailsFormatter $detailsFormatter,
        private ReportPayloadFactory $payloadFactory,
        private ReportCopyTexts $copyTexts,
        private BackendLinkBuilder $links,
        private BackendUserNames $userNames,
        private UriBuilder $uriBuilder,
        private IconFactory $iconFactory,
        private SubjectPresenter $subjectPresenter,
        private PageRenderer $pageRenderer,
        private ConfiguredSecrets $secrets,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->accessDenied();
        }
        $query = $request->getQueryParams();
        $state = DeliveryState::tryFrom(is_string($query['state'] ?? null) ? $query['state'] : '');
        $reviewFilter = is_string($query['review'] ?? null) && ($query['review'] === self::REVIEW_FILTER_ALL || ReviewState::tryFrom($query['review']) !== null)
            ? $query['review']
            : ReviewState::Open->value;
        $review = ReviewState::tryFrom($reviewFilter);

        $total = $this->reports->count($state, $review);
        $pageCount = max(1, (int)ceil($total / self::ITEMS_PER_PAGE));
        $page = is_numeric($query['page'] ?? null) ? min($pageCount, max(1, (int)$query['page'])) : 1;
        $reports = $this->reports->findLatest(($page - 1) * self::ITEMS_PER_PAGE, self::ITEMS_PER_PAGE, $state, $review);
        $names = $this->userNames->resolve(array_map(static fn(Report $report): int => $report->reporterUid, $reports));

        $items = [];
        foreach ($reports as $report) {
            $items[] = [
                'report' => $report,
                'reporter' => $names[$report->reporterUid]['label'] ?? '',
                'stateLabel' => $this->translate('state.' . $report->deliveryState->value),
                'stateClass' => $this->getStateClass($report->deliveryState),
                'reviewLabel' => $this->translate('review.' . $report->reviewState->value),
                'reviewClass' => $this->getReviewClass($report->reviewState),
                'subject' => $this->subjectPresenter->present($report->document),
                'showUrl' => $this->buildModuleUrl('show', ['report' => $report->identifier]),
            ];
        }

        $deliveryParameters = $state !== null ? ['state' => $state->value] : [];
        $reviewParameters = ['review' => $reviewFilter];
        $reviewFilters = [];
        foreach ([ReviewState::Open->value, ReviewState::Resolved->value, self::REVIEW_FILTER_ALL] as $value) {
            $reviewFilters[] = [
                'label' => $this->translate($value === self::REVIEW_FILTER_ALL ? 'filter.all' : 'review.' . $value),
                'count' => $this->reports->count($state, ReviewState::tryFrom($value)),
                'active' => $value === $reviewFilter,
                'url' => $this->buildModuleUrl(parameters: ['review' => $value] + $deliveryParameters),
            ];
        }
        $filters = [['label' => $this->translate('filter.all'), 'active' => $state === null, 'url' => $this->buildModuleUrl(parameters: $reviewParameters)]];
        foreach (DeliveryState::cases() as $case) {
            $filters[] = [
                'label' => $this->translate('state.' . $case->value),
                'active' => $state === $case,
                'url' => $this->buildModuleUrl(parameters: $reviewParameters + ['state' => $case->value]),
            ];
        }
        $pageParameters = $reviewParameters + $deliveryParameters;

        $view = $this->createView($request);
        $this->addLinkButton($view, $this->buildModuleUrl('settings'), 'button.settings', 'actions-cog', 1, ButtonBar::BUTTON_POSITION_RIGHT);
        $view->assignMultiple([
            'items' => $items,
            'filters' => $filters,
            'reviewFilters' => $reviewFilters,
            'emptyState' => match (true) {
                $items !== [] => '',
                $this->reports->count() === 0 => 'empty',
                $state === null && $review === ReviewState::Open => 'emptyOpen',
                default => 'emptyFilter',
            },
            'total' => $total,
            'destinations' => $this->deliveryService->describeEnabledDestinations(),
            'settingsUrl' => $this->buildModuleUrl('settings', ['tab' => 'email']),
            'pagination' => [
                'current' => $page,
                'count' => $pageCount,
                'previousUrl' => $page > 1 ? $this->buildModuleUrl(parameters: $pageParameters + ['page' => $page - 1]) : '',
                'nextUrl' => $page < $pageCount ? $this->buildModuleUrl(parameters: $pageParameters + ['page' => $page + 1]) : '',
            ],
        ]);
        return $view->renderResponse('ReportModule/Index');
    }

    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->accessDenied();
        }
        $report = $this->findReport($request->getQueryParams()['report'] ?? null);
        if ($report === null) {
            return $this->redirectWithMessage($request, $this->translate('message.notFound'), ContextualFeedbackSeverity::WARNING);
        }

        $attempts = $this->attempts->findByReportUid($report->uid);
        $names = $this->userNames->resolve(array_merge(
            [$report->reporterUid, $report->resolvedBy],
            array_map(static fn(DeliveryAttempt $attempt): int => $attempt->triggeredBy, $attempts),
        ));
        // Details may have been stored by earlier versions or under other secrets: sanitize them again
        $secrets = $this->secrets->get();
        $attemptItems = [];
        foreach (array_reverse($attempts) as $attempt) {
            $attemptItems[] = [
                'attempt' => $attempt,
                'message' => SafeErrorMessage::sanitize($attempt->message, $secrets),
                'externalReference' => ExternalReferenceSanitizer::reference($attempt->externalReference, $secrets),
                'externalUrl' => ExternalReferenceSanitizer::url($attempt->externalUrl, $secrets),
                'label' => $this->translate('destination.' . $attempt->destination),
                'successful' => $attempt->status === DeliveryStatus::Succeeded,
                'triggeredBy' => $names[$attempt->triggeredBy]['label'] ?? '',
                'canRetry' => false,
            ];
        }
        // Only the latest failed attempt of a destination can be retried
        $latest = $this->deliveryService->findLatestAttempts($report);
        foreach ($attemptItems as $index => $item) {
            $attempt = $item['attempt'];
            $attemptItems[$index]['canRetry'] = ($latest[$attempt->destination] ?? null)?->uid === $attempt->uid
                && $this->deliveryService->canRetry($attempt);
        }

        $reportUrl = $this->links->report($report->identifier);
        // Without screenshot content: the payload is shown and copied as text
        $payload = $this->payloadFactory->create($report, $reportUrl);
        $copyTexts = $this->copyTexts->create($payload, $reportUrl);
        $subject = $this->subjectPresenter->present($report->document);
        $view = $this->createView($request);
        $this->addLinkButton($view, $this->buildModuleUrl(), 'button.reports', 'actions-view-go-back', 1);
        $this->addCopyButton($view, $copyTexts);
        $this->addDownloadButton($view, $report);
        $view->assignMultiple([
            'report' => $report,
            'subject' => $subject,
            'reporter' => $names[$report->reporterUid] ?? null,
            'stateLabel' => $this->translate('state.' . $report->deliveryState->value),
            'stateClass' => $this->getStateClass($report->deliveryState),
            'reviewLabel' => $this->translate('review.' . $report->reviewState->value),
            'reviewClass' => $this->getReviewClass($report->reviewState),
            'resolved' => $report->reviewState === ReviewState::Resolved,
            'resolvedBy' => $report->resolvedBy > 0 ? ($names[$report->resolvedBy]['label'] ?? '') : '',
            'resolveUrl' => $this->buildModuleUrl('resolve'),
            'reopenUrl' => $this->buildModuleUrl('reopen'),
            'sourceLabel' => $this->translate('source.' . $report->source->value),
            'sections' => $this->detailsFormatter->buildSections($payload),
            'attempts' => $attemptItems,
            'json' => $copyTexts['json'],
            'screenshotUrl' => $report->screenshot !== null ? $this->buildDownloadUrl($report, 'screenshot') : '',
            'screenshotDownloadUrl' => $report->screenshot !== null ? $this->buildDownloadUrl($report, 'screenshot', ['download' => '1']) : '',
            'screenshotSize' => $report->screenshot !== null ? MarkdownReportExporter::formatBytes($report->screenshot->size) : '',
            'subjectUrl' => $this->buildSubjectUrl($report, $request),
            'subjectUrlIcon' => $report->document->getSubjectType() === SubjectType::Record ? 'actions-open' : 'actions-window-open',
            'metadataUrl' => $this->buildMetadataUrl($report, $request),
            'frontendUrl' => $subject->frontendUrl,
            'retryUrl' => $this->buildModuleUrl('retry'),
            'deleteUrl' => $this->buildModuleUrl('delete'),
            'indexUrl' => $this->buildModuleUrl(),
        ]);
        return $view->renderResponse('ReportModule/Show');
    }

    public function retryAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->accessDenied();
        }
        $body = $request->getParsedBody();
        $report = $this->findReport(is_array($body) ? ($body['report'] ?? null) : null);
        $destination = is_array($body) && is_string($body['destination'] ?? null) ? $body['destination'] : '';
        if ($report === null) {
            return $this->redirectWithMessage($request, $this->translate('message.notFound'), ContextualFeedbackSeverity::WARNING);
        }
        $label = $this->translate('destination.' . $destination);
        try {
            $attempt = $this->deliveryService->retry($report, $destination, $this->getBackendUserId());
        } catch (DeliveryNotPossibleException $exception) {
            return $this->redirectToReport($request, sprintf($this->translate('message.retry.' . $exception->getReason()), $label), ContextualFeedbackSeverity::WARNING, $report);
        }
        return $attempt->status === DeliveryStatus::Succeeded
            ? $this->redirectToReport($request, sprintf($this->translate('message.retry.succeeded'), $label), ContextualFeedbackSeverity::OK, $report)
            : $this->redirectToReport($request, sprintf($this->translate('message.retry.failed'), $label, $attempt->message), ContextualFeedbackSeverity::ERROR, $report);
    }

    public function deleteAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->accessDenied();
        }
        $body = $request->getParsedBody();
        $report = $this->findReport(is_array($body) ? ($body['report'] ?? null) : null);
        if ($report === null) {
            return $this->redirectWithMessage($request, $this->translate('message.notFound'), ContextualFeedbackSeverity::WARNING);
        }
        // Removes the screenshot and the delivery history together with the report
        $this->reports->delete($report->uid);
        return $this->redirectWithMessage($request, sprintf($this->translate('message.deleted'), $report->identifier), ContextualFeedbackSeverity::OK);
    }

    public function resolveAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->changeReviewState($request, ReviewState::Resolved);
    }

    public function reopenAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->changeReviewState($request, ReviewState::Open);
    }

    private function changeReviewState(ServerRequestInterface $request, ReviewState $target): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->accessDenied();
        }
        $body = $request->getParsedBody();
        $report = $this->findReport(is_array($body) ? ($body['report'] ?? null) : null);
        if ($report === null) {
            return $this->redirectWithMessage($request, $this->translate('message.notFound'), ContextualFeedbackSeverity::WARNING);
        }
        $changed = $target === ReviewState::Resolved
            ? $this->reports->markResolved($report->uid, $this->getBackendUserId(), time())
            : $this->reports->reopen($report->uid);
        return $changed
            ? $this->redirectToReport($request, sprintf($this->translate($target === ReviewState::Resolved ? 'message.resolved' : 'message.reopened'), $report->identifier), ContextualFeedbackSeverity::OK, $report)
            : $this->redirectToReport($request, sprintf($this->translate('message.reviewUnchanged'), $report->identifier), ContextualFeedbackSeverity::INFO, $report);
    }

    /**
     * @param array{summary: string, markdown: string, json: string, link: string} $texts
     */
    private function addCopyButton(ModuleTemplate $view, array $texts): void
    {
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/copy-to-clipboard.js');
        $this->pageRenderer->addInlineLanguageLabelFile('EXT:backend/Resources/Private/Language/locallang_copytoclipboard.xlf');
        $button = GeneralUtility::makeInstance(DropDownButton::class)
            ->setLabel($this->translate('button.copy'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-clipboard', IconSize::SMALL));
        $icons = ['summary' => 'mimetypes-text-text', 'markdown' => 'actions-file-text', 'json' => 'actions-code', 'link' => 'actions-link'];
        foreach ($texts as $key => $text) {
            $item = GeneralUtility::makeInstance(DropDownItem::class);
            $item->setTag('typo3-copy-to-clipboard');
            $item->setLabel($this->translate('button.copy.' . $key));
            $item->setIcon($this->iconFactory->getIcon($icons[$key], IconSize::SMALL));
            $item->setAttributes(['text' => $text]);
            $button->addItem($item);
        }
        $view->getDocHeaderComponent()->getButtonBar()->addButton($button, ButtonBar::BUTTON_POSITION_LEFT, 2);
    }

    private function addDownloadButton(ModuleTemplate $view, Report $report): void
    {
        $downloads = [
            ['button.download.markdown', $this->buildDownloadUrl($report, 'markdown'), 'actions-file-text'],
            [$report->screenshot !== null ? 'button.download.jsonWithScreenshot' : 'button.download.json', $this->buildDownloadUrl($report, 'json'), 'actions-code'],
        ];
        if ($report->screenshot !== null) {
            $downloads[] = ['button.download.screenshot', $this->buildDownloadUrl($report, 'screenshot', ['download' => '1']), 'actions-image'];
        }
        $button = GeneralUtility::makeInstance(DropDownButton::class)
            ->setLabel($this->translate('button.download'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-download', IconSize::SMALL));
        foreach ($downloads as [$label, $href, $icon]) {
            $item = GeneralUtility::makeInstance(DropDownItem::class);
            $item->setLabel($this->translate($label));
            $item->setHref($href);
            $item->setIcon($this->iconFactory->getIcon($icon, IconSize::SMALL));
            $item->setAttributes(['download' => '']);
            $button->addItem($item);
        }
        $view->getDocHeaderComponent()->getButtonBar()->addButton($button, ButtonBar::BUTTON_POSITION_LEFT, 2);
    }

    /**
     * Where the reported object can be opened, with the route token of the current session.
     */
    private function buildSubjectUrl(Report $report, ServerRequestInterface $request): string
    {
        $document = $report->document;
        $subject = $document->getSubject();
        try {
            return match ($document->getSubjectType()) {
                SubjectType::Record => (int)($subject['uid'] ?? 0) > 0
                    ? $this->buildEditUrl((string)$subject['table'], (int)$subject['uid'], $request)
                    : '',
                SubjectType::Page => (string)$this->uriBuilder->buildUriFromRoute('web_layout', ['id' => (int)($subject['uid'] ?? 0)]),
                SubjectType::File => $this->buildFileListUrl(
                    (string)($document->getContextSection('folder')['storageUid'] ?? '') . ':' . (string)($document->getContextSection('folder')['identifier'] ?? ''),
                ),
                SubjectType::Folder => $this->buildFileListUrl((string)($subject['identifier'] ?? '')),
                SubjectType::Backend => isset($subject['module']) ? (string)$this->uriBuilder->buildUriFromRoute((string)$subject['module']) : '',
            };
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * The metadata record of a reported file.
     */
    private function buildMetadataUrl(Report $report, ServerRequestInterface $request): string
    {
        $metadataUid = $report->document->getContextSection('file')['metadataUid'] ?? 0;
        if ($report->document->getSubjectType() !== SubjectType::File || !is_int($metadataUid) || $metadataUid <= 0) {
            return '';
        }
        try {
            return $this->buildEditUrl('sys_file_metadata', $metadataUid, $request);
        } catch (\Throwable) {
            return '';
        }
    }

    private function buildEditUrl(string $table, int $uid, ServerRequestInterface $request): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [$table => [$uid => 'edit']],
            'returnUrl' => (string)$request->getUri(),
        ]);
    }

    private function buildFileListUrl(string $combinedFolderIdentifier): string
    {
        return ReportTarget::isValidFolderIdentifier($combinedFolderIdentifier)
            ? (string)$this->uriBuilder->buildUriFromRoute(BackendLinkBuilder::FILE_LIST_MODULE, ['id' => $combinedFolderIdentifier])
            : '';
    }

    /**
     * @param array<string, string> $parameters
     */
    private function buildDownloadUrl(Report $report, string $format, array $parameters = []): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute(
            ReportDownloadController::ROUTE,
            ['report' => $report->identifier, 'format' => $format] + $parameters,
        );
    }

    private function redirectToReport(ServerRequestInterface $request, string $message, ContextualFeedbackSeverity $severity, Report $report): ResponseInterface
    {
        return $this->redirectWithMessage($request, $message, $severity, 'show', ['report' => $report->identifier]);
    }

    private function findReport(mixed $identifier): ?Report
    {
        return is_string($identifier) && ReportIdGenerator::isValid($identifier)
            ? $this->reports->findByIdentifier($identifier)
            : null;
    }

    private function getStateClass(DeliveryState $state): string
    {
        return match ($state) {
            DeliveryState::Delivered => 'success',
            DeliveryState::Partial => 'warning',
            DeliveryState::Failed => 'danger',
            DeliveryState::Local => 'default',
        };
    }

    private function getReviewClass(ReviewState $state): string
    {
        return match ($state) {
            ReviewState::Open => 'info',
            ReviewState::Resolved => 'success',
        };
    }
}
