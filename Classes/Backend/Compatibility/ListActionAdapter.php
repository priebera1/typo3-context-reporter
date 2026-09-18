<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Backend\Compatibility;

use Priebera\ContextReporter\Backend\ReportTriggerFactory;
use Priebera\ContextReporter\Domain\ReportSource;
use TYPO3\CMS\Backend\RecordList\Event\ModifyRecordListRecordActionsEvent;
use TYPO3\CMS\Backend\Template\Components\ActionGroup;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Filelist\Event\ProcessFileListActionsEvent;

/**
 * The only place that knows how the record list and the file list of the
 * running TYPO3 version take a row action.
 *
 * TYPO3 v14 reworked both APIs (#107884, "Rework actions to use Buttons API
 * with Components"):
 *
 * - v13 hands a listener one array of HTML strings and splits it into the
 *   directly visible actions and the "More options" menu afterwards; v14 hands
 *   it two Button API component groups that are already split, so the listener
 *   has to choose the group itself.
 * - The record list event of v13 exposes the table and the raw row; v14
 *   exposes a Record API object instead.
 *
 * ActionGroup exists as of TYPO3 v14 only. The import is harmless on v13: the
 * class is never referenced outside the v14 branches, so it is never loaded.
 *
 * Everything else in the extension works with the table name, the raw row and
 * a report trigger, on both branches.
 *
 * @internal
 */
final readonly class ListActionAdapter
{
    /**
     * What TYPO3 shows directly in the file list when
     * options.file_list.primaryActions is not set (FileList::renderControlManage()).
     */
    private const DEFAULT_PRIMARY_FILE_ACTIONS = 'view,metadata,translations,delete';

    public function __construct(
        private ReportTriggerFactory $triggers,
        private Typo3Version $typo3Version,
    ) {}

    /**
     * Whether the list actions of this TYPO3 version are Button API components
     * in named groups (v14) instead of HTML strings in one array (v13).
     */
    public function usesComponents(): bool
    {
        return $this->typo3Version->getMajorVersion() >= 14;
    }

    public function getRecordListTable(ModifyRecordListRecordActionsEvent $event): string
    {
        if (!$this->usesComponents()) {
            return $event->getTable();
        }
        return $event->getRecord()->getMainType();
    }

    /**
     * The raw row of the record the list is rendering, with the workspace
     * overlay the list applied.
     *
     * @return array<string, mixed>
     */
    public function getRecordListRow(ModifyRecordListRecordActionsEvent $event): array
    {
        $record = $event->getRecord();
        if (!$this->usesComponents()) {
            return $record;
        }
        $row = $record->getRawRecord()?->toArray();
        return is_array($row) ? $row : ['uid' => $record->getUid()];
    }

    /**
     * @param array<string, string> $targetAttributes data-context-reporter-* attributes of the object
     */
    public function addRecordListAction(
        ModifyRecordListRecordActionsEvent $event,
        ReportSource $source,
        array $targetAttributes,
        string $label,
    ): void {
        if (!$this->usesComponents()) {
            $event->setAction(
                $this->triggers->renderActionButton($source, $targetAttributes, $label),
                ReportTriggerFactory::ACTION_NAME,
                'primary',
            );
            return;
        }
        $event->setAction(
            $this->triggers->createActionButton($source, $targetAttributes, $label),
            ReportTriggerFactory::ACTION_NAME,
            ActionGroup::primary,
        );
    }

    /**
     * Adds the action behind the delete action of the file list. Whether it is
     * shown directly or in the "More options" menu follows
     * options.file_list.primaryActions, like every other file list action.
     *
     * @param array<string, string> $targetAttributes data-context-reporter-* attributes of the object
     */
    public function addFileListAction(
        ProcessFileListActionsEvent $event,
        ReportSource $source,
        array $targetAttributes,
        string $label,
    ): void {
        if (!$this->usesComponents()) {
            $event->setActionItems($this->insertAfter(
                $event->getActionItems(),
                'delete',
                ReportTriggerFactory::ACTION_NAME,
                $this->triggers->renderActionButton($source, $targetAttributes, $label, 'btn btn-sm btn-default'),
            ));
            return;
        }
        $event->setAction(
            $this->triggers->createActionButton($source, $targetAttributes, $label),
            ReportTriggerFactory::ACTION_NAME,
            $this->isPrimaryFileListAction(ReportTriggerFactory::ACTION_NAME) ? ActionGroup::primary : ActionGroup::secondary,
            '',
            'delete',
        );
    }

    /**
     * TYPO3 v13 evaluates options.file_list.primaryActions after the event, v14
     * before it, so on v14 the listener has to answer the same question itself.
     */
    private function isPrimaryFileListAction(string $actionName): bool
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $configured = $backendUser instanceof BackendUserAuthentication
            ? ($backendUser->getTSConfig()['options.']['file_list.']['primaryActions'] ?? null)
            : null;
        $primaryActions = GeneralUtility::trimExplode(',', is_string($configured) ? $configured : self::DEFAULT_PRIMARY_FILE_ACTIONS, true);
        return in_array($actionName, $primaryActions, true);
    }

    /**
     * @param array<array-key, mixed> $items
     * @return array<array-key, mixed>
     */
    private function insertAfter(array $items, string $after, string $key, string $value): array
    {
        $position = array_search($after, array_keys($items), true);
        if ($position === false) {
            return $items + [$key => $value];
        }
        return array_slice($items, 0, $position + 1, true) + [$key => $value] + array_slice($items, $position + 1, null, true);
    }
}
