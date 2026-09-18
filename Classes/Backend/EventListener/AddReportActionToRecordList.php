<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Backend\EventListener;

use Priebera\ContextReporter\Backend\Compatibility\ListActionAdapter;
use Priebera\ContextReporter\Context\Subject\RecordAccess;
use Priebera\ContextReporter\Domain\ReportSource;
use Priebera\ContextReporter\Security\ReportAccessPolicy;
use TYPO3\CMS\Backend\RecordList\Event\ModifyRecordListRecordActionsEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Adds a compact "Report problem" action to the primary actions of every
 * reportable record row in the record list (Web > List).
 *
 * The list only shows records the user may see; the report dialog checks the
 * record again on the server.
 *
 * @internal
 */
#[AsEventListener('context-reporter/record-list-report-action')]
final readonly class AddReportActionToRecordList
{
    private const LABELS = 'LLL:EXT:context_reporter/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private ReportAccessPolicy $accessPolicy,
        private RecordAccess $recordAccess,
        private ListActionAdapter $listActions,
    ) {}

    public function __invoke(ModifyRecordListRecordActionsEvent $event): void
    {
        $table = $this->listActions->getRecordListTable($event);
        $row = $this->listActions->getRecordListRow($event);
        $uid = $row['uid'] ?? null;
        $uid = is_int($uid) || (is_string($uid) && preg_match('/^\d{1,10}$/D', $uid)) ? (int)$uid : 0;
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($uid <= 0
            || VersionState::tryFrom((int)($row['t3ver_state'] ?? 0)) === VersionState::DELETE_PLACEHOLDER
            || !$backendUser instanceof BackendUserAuthentication
            || !$this->accessPolicy->canReport($backendUser)
            || !$this->recordAccess->isAllowedTable($table, $backendUser)
        ) {
            return;
        }

        $label = $this->getLanguageService()->sL(self::LABELS . ($table === 'pages' ? 'action.reportPage' : 'action.reportRecord'));
        $this->listActions->addRecordListAction(
            $event,
            ReportSource::RecordList,
            ['data-context-reporter-table' => $table, 'data-context-reporter-uid' => (string)$uid],
            $label,
        );
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
