<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Backend\EventListener;

use Priebera\ContextReporter\Backend\ReportTriggerFactory;
use Priebera\ContextReporter\Context\Subject\RecordAccess;
use Priebera\ContextReporter\Domain\ReportSource;
use Priebera\ContextReporter\Security\ReportAccessPolicy;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Adds a compact "Report problem with this page" button to the document
 * header of the Page module (route "web_layout"), next to the reload and
 * cache actions, through the Core button bar event.
 *
 * @internal
 */
#[AsEventListener('context-reporter/page-module-report-button')]
final readonly class AddReportButtonToPageModule
{
    /** Between the "clear cache / reload" group (1) and the "View" menu (3) */
    public const BUTTON_GROUP = 2;
    private const PAGE_MODULE = 'web_layout';
    private const LABELS = 'LLL:EXT:context_reporter/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private ReportAccessPolicy $accessPolicy,
        private RecordAccess $recordAccess,
        private ReportTriggerFactory $triggers,
    ) {}

    public function __invoke(ModifyButtonBarEvent $event): void
    {
        $request = $this->triggers->getRequest($event);
        $route = $request?->getAttribute('route');
        if ($request === null || !$route instanceof Route || $route->getOption('_identifier') !== self::PAGE_MODULE) {
            return;
        }
        $body = $request->getParsedBody();
        $id = $request->getQueryParams()['id'] ?? (is_array($body) ? ($body['id'] ?? null) : null);
        $pageUid = is_string($id) && preg_match('/^[1-9]\d{0,9}$/D', $id) ? (int)$id : 0;
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($pageUid === 0
            || !$backendUser instanceof BackendUserAuthentication
            || !$this->accessPolicy->canReport($backendUser)
            || $this->recordAccess->findPage($pageUid, $backendUser) === null
        ) {
            return;
        }

        $label = $this->getLanguageService()->sL(self::LABELS . 'action.reportPage');
        $button = $this->triggers->createDocHeaderButton(
            ReportSource::PageModule,
            ['data-context-reporter-table' => 'pages', 'data-context-reporter-uid' => (string)$pageUid],
            $label,
            $label,
            false,
        );
        $this->triggers->addToDocHeader($event, $button, self::BUTTON_GROUP);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
