<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Backend;

use TYPO3\CMS\Backend\RecordList\DatabaseRecordList;
use TYPO3\CMS\Backend\RecordList\Event\ModifyRecordListRecordActionsEvent;
use TYPO3\CMS\Backend\Template\Components\ActionGroup;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\GenericButton;
use TYPO3\CMS\Backend\Template\Components\ComponentGroup;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Domain\RecordFactory;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Resource\ResourceInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Filelist\Event\ProcessFileListActionsEvent;

/**
 * Builds the record list, file list and button bar events of the running TYPO3
 * version and reads the resulting actions back as markup, so the tests can
 * assert the same behaviour on TYPO3 13.4 and 14.3.
 *
 * TYPO3 v14 replaced the action arrays of both list events with Button API
 * component groups (#107884); see ListActionAdapter for the production side.
 *
 * @internal
 */
trait ListActionEvents
{
    private function usesActionComponents(): bool
    {
        return (new Typo3Version())->getMajorVersion() >= 14;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function createRecordListEvent(string $table, array $row): ModifyRecordListRecordActionsEvent
    {
        $recordList = GeneralUtility::makeInstance(DatabaseRecordList::class);
        if (!$this->usesActionComponents()) {
            return new ModifyRecordListRecordActionsEvent(
                ['primary' => ['edit' => $this->stubAction('edit'), 'delete' => $this->stubAction('delete')], 'secondary' => []],
                $table,
                $row,
                $recordList,
            );
        }
        $primary = new ComponentGroup('primary');
        $primary->add('edit', $this->stubComponent('edit'));
        $primary->add('delete', $this->stubComponent('delete'));
        return new ModifyRecordListRecordActionsEvent(
            $primary,
            new ComponentGroup('secondary'),
            // A raw record carries exactly what the adapter reads; the resolved record
            // of the real list would need every language and versioning field of the table
            $this->get(RecordFactory::class)->createRawRecord($table, $row),
            $recordList,
            $this->createBackendRequest(),
        );
    }

    /**
     * @param list<string> $actionNames the actions the list itself offers, in order
     */
    private function createFileListEvent(ResourceInterface $resource, array $actionNames): ProcessFileListActionsEvent
    {
        if (!$this->usesActionComponents()) {
            $actions = [];
            foreach ($actionNames as $name) {
                $actions[$name] = $this->stubAction($name);
            }
            return new ProcessFileListActionsEvent($resource, $actions);
        }
        // TYPO3 v14 splits the actions into the two groups before the event is dispatched
        $primary = new ComponentGroup('primary');
        $secondary = new ComponentGroup('secondary');
        $primaryNames = $this->primaryFileListActions();
        foreach ($actionNames as $name) {
            $group = in_array($name, $primaryNames, true) ? $primary : $secondary;
            $group->add($name, $this->stubComponent($name));
        }
        return new ProcessFileListActionsEvent($primary, $secondary, $resource, $this->createBackendRequest());
    }

    /**
     * The actions of the group, in the order the list renders them.
     *
     * @return list<string>
     */
    private function listActionNames(ModifyRecordListRecordActionsEvent|ProcessFileListActionsEvent $event, string $group = 'primary'): array
    {
        if ($this->usesActionComponents()) {
            $actionGroup = $group === 'primary' ? ActionGroup::primary : ActionGroup::secondary;
            return array_values(array_map(strval(...), array_keys($event->getActionGroup($actionGroup)->getItems())));
        }
        if ($event instanceof ProcessFileListActionsEvent) {
            // TYPO3 v13 splits the file list actions into the two groups after the event
            $names = array_keys(array_filter($event->getActionItems(), static fn($action) => $action !== null && trim((string)$action) !== ''));
            $primaryNames = $this->primaryFileListActions();
            $isPrimary = $group === 'primary';
            return array_values(array_map(strval(...), array_filter($names, static fn($name) => in_array((string)$name, $primaryNames, true) === $isPrimary)));
        }
        $actions = $event->getActionGroup($group);
        return array_values(array_map(strval(...), array_keys(array_filter(is_array($actions) ? $actions : [], static fn($action) => $action !== null && trim((string)$action) !== ''))));
    }

    /**
     * The rendered action, as the list writes it into the row.
     */
    private function listActionMarkup(ModifyRecordListRecordActionsEvent|ProcessFileListActionsEvent $event, string $name): string
    {
        $action = $event instanceof ProcessFileListActionsEvent && !$this->usesActionComponents()
            ? ($event->getActionItems()[$name] ?? null)
            : $event->getAction($name);
        return $this->usesActionComponents() ? (string)$action?->render() : (string)$action;
    }

    private function hasListAction(ModifyRecordListRecordActionsEvent|ProcessFileListActionsEvent $event, string $name): bool
    {
        if ($event instanceof ProcessFileListActionsEvent && !$this->usesActionComponents()) {
            return array_key_exists($name, $event->getActionItems());
        }
        return $event->hasAction($name);
    }

    /**
     * TYPO3 v14 passes the request with the button bar event, v13 does not.
     *
     * @param array<'left'|'right', array<int, list<\TYPO3\CMS\Backend\Template\Components\Buttons\ButtonInterface>>> $buttons
     */
    private function createButtonBarEvent(array $buttons = []): ModifyButtonBarEvent
    {
        $buttonBar = GeneralUtility::makeInstance(ButtonBar::class);
        if (!$this->usesActionComponents()) {
            return new ModifyButtonBarEvent($buttons, $buttonBar);
        }
        return new ModifyButtonBarEvent($buttons, $buttonBar, $GLOBALS['TYPO3_REQUEST'] ?? $this->createBackendRequest());
    }

    /**
     * @return list<string>
     */
    private function primaryFileListActions(): array
    {
        $configured = $GLOBALS['BE_USER']->getTSConfig()['options.']['file_list.']['primaryActions'] ?? null;
        return GeneralUtility::trimExplode(',', is_string($configured) ? $configured : 'view,metadata,translations,delete', true);
    }

    private function stubAction(string $name): string
    {
        return '<a class="btn btn-default" data-stub="' . $name . '">' . $name . '</a>';
    }

    private function stubComponent(string $name): GenericButton
    {
        return GeneralUtility::makeInstance(GenericButton::class)
            ->setTag('a')
            ->setLabel($name)
            ->setAttributes(['data-stub' => $name]);
    }
}
