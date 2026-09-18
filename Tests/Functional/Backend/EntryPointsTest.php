<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Backend\ContextMenu\ReportResourceItemProvider;
use Priebera\ContextReporter\Backend\EventListener\AddReportActionToFileList;
use Priebera\ContextReporter\Backend\EventListener\AddReportActionToRecordList;
use Priebera\ContextReporter\Backend\ReportTriggerFactory;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\ContextMenu\ContextMenu;
use TYPO3\CMS\Backend\RecordList\Event\ModifyRecordListRecordActionsEvent;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\GenericButton;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class EntryPointsTest extends AbstractContextReporterTestCase
{
    use ListActionEvents;

    #[Test]
    public function recordListRowsGetACompactReportAction(): void
    {
        $this->loginBackendUser(self::EDITOR);

        $event = $this->dispatchRecordActions('tt_content', ['uid' => 10, 'pid' => 2, 'CType' => 'text']);
        self::assertSame(['edit', 'delete', ReportTriggerFactory::ACTION_NAME], $this->listActionNames($event));
        $action = $this->listActionMarkup($event, ReportTriggerFactory::ACTION_NAME);
        self::assertStringStartsWith('<button ', $action);
        self::assertMatchesRegularExpression('/class="btn( btn-sm)? btn-default"/', $action);
        self::assertStringContainsString('type="button"', $action);
        self::assertStringContainsString('data-context-reporter-trigger="recordList"', $action);
        self::assertStringContainsString('data-context-reporter-table="tt_content"', $action);
        self::assertStringContainsString('data-context-reporter-uid="10"', $action);
        self::assertStringContainsString('title="Problem mit diesem Datensatz melden"', $action);
        self::assertStringContainsString('aria-label="Problem mit diesem Datensatz melden"', $action);
        self::assertStringContainsString('data-identifier="context-reporter-report"', $action);

        $pageEvent = $this->dispatchRecordActions('pages', ['uid' => 3, 'pid' => 2, 'doktype' => 254]);
        $pageAction = $this->listActionMarkup($pageEvent, ReportTriggerFactory::ACTION_NAME);
        self::assertStringContainsString('data-context-reporter-table="pages"', $pageAction);
        self::assertStringContainsString('title="Problem mit dieser Seite melden"', $pageAction);
    }

    #[Test]
    public function recordListActionIsOmittedForUnreportableRows(): void
    {
        $this->loginBackendUser(self::EDITOR);

        self::assertFalse($this->hasListAction($this->dispatchRecordActions('be_users', ['uid' => 1, 'pid' => 0, 'admin' => 1]), ReportTriggerFactory::ACTION_NAME));
        self::assertFalse($this->hasListAction($this->dispatchRecordActions('sys_file', ['uid' => 1, 'pid' => 0, 'type' => '2']), ReportTriggerFactory::ACTION_NAME));
        self::assertFalse($this->hasListAction($this->dispatchRecordActions('tt_content', ['uid' => 0, 'pid' => 2, 'CType' => 'text']), ReportTriggerFactory::ACTION_NAME), 'a record that has not been saved yet');
        self::assertFalse($this->hasListAction($this->dispatchRecordActions('tt_content', ['uid' => 10, 'pid' => 2, 'CType' => 'text', 't3ver_state' => 2]), ReportTriggerFactory::ACTION_NAME));

        $this->loginBackendUser(self::BLOCKED_EDITOR);
        self::assertFalse($this->hasListAction($this->dispatchRecordActions('tt_content', ['uid' => 10, 'pid' => 2, 'CType' => 'text']), ReportTriggerFactory::ACTION_NAME));
    }

    #[Test]
    public function pageModuleGetsACompactPageReportButton(): void
    {
        $this->loginBackendUser(self::EDITOR);
        $this->setRequestForRoute('web_layout', ['id' => '2']);

        $buttons = $this->dispatchButtonBar([
            ButtonBar::BUTTON_POSITION_RIGHT => [1 => [new GenericButton()], 3 => [new GenericButton()]],
        ]);
        $button = $this->findReportButton($buttons);

        self::assertNotNull($button);
        self::assertFalse($button->getShowLabelText());
        self::assertSame('Problem mit dieser Seite melden', $button->getTitle());
        self::assertSame([
            'type' => 'button',
            'aria-label' => 'Problem mit dieser Seite melden',
            'data-context-reporter-trigger' => 'pageModule',
            'data-context-reporter-table' => 'pages',
            'data-context-reporter-uid' => '2',
        ], $button->getAttributes());
        self::assertSame('context-reporter-report', $button->getIcon()?->getIdentifier());
        self::assertSame([1, 2, 3], array_keys($buttons[ButtonBar::BUTTON_POSITION_RIGHT]), 'The report button gets its own group between the existing ones');
    }

    #[Test]
    public function pageModuleButtonRequiresAnAccessiblePage(): void
    {
        $this->loginBackendUser(self::EDITOR);
        foreach ([['id' => '4'], ['id' => '0'], [], ['id' => 'abc']] as $parameters) {
            $this->setRequestForRoute('web_layout', $parameters);
            self::assertNull($this->findReportButton($this->dispatchButtonBar()), json_encode($parameters, JSON_THROW_ON_ERROR));
        }
        $this->setRequestForRoute('web_list', ['id' => '2']);
        self::assertNull($this->findReportButton($this->dispatchButtonBar()));

        $this->loginBackendUser(self::BLOCKED_EDITOR);
        $this->setRequestForRoute('web_layout', ['id' => '2']);
        self::assertNull($this->findReportButton($this->dispatchButtonBar()));
    }

    #[Test]
    public function fileListOffersAReportActionForFilesAndFolders(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $resourceFactory = $this->get(ResourceFactory::class);

        $event = $this->createFileListEvent($resourceFactory->getFileObject(self::FILE_LOGO), ['view', 'delete', 'copy']);
        $this->get(AddReportActionToFileList::class)($event);
        self::assertSame(['view', 'delete', ReportTriggerFactory::ACTION_NAME], $this->listActionNames($event), 'The report action follows the delete action');
        $fileAction = $this->listActionMarkup($event, ReportTriggerFactory::ACTION_NAME);
        self::assertStringStartsWith('<button ', $fileAction);
        self::assertMatchesRegularExpression('/class="btn btn-sm btn-default"/', $fileAction);
        self::assertStringContainsString('type="button"', $fileAction);
        self::assertStringContainsString('data-context-reporter-trigger="fileList"', $fileAction);
        self::assertStringContainsString('data-context-reporter-table="sys_file"', $fileAction);
        self::assertStringContainsString('data-context-reporter-uid="1"', $fileAction);
        self::assertStringContainsString('title="Report problem with this file"', $fileAction);

        $event = $this->createFileListEvent($resourceFactory->getFolderObjectFromCombinedIdentifier('1:/private/'), ['delete']);
        $this->get(AddReportActionToFileList::class)($event);
        $folderAction = $this->listActionMarkup($event, ReportTriggerFactory::ACTION_NAME);
        self::assertStringContainsString('data-context-reporter-folder="1:/private/"', $folderAction);
        self::assertStringNotContainsString('data-context-reporter-uid', $folderAction);
        self::assertStringContainsString('title="Report problem with this folder"', $folderAction);
    }

    #[Test]
    public function fileListActionIsAPrimaryActionAndRespectsTheAccessPolicy(): void
    {
        $backendUser = $this->loginBackendUser(self::EDITOR);
        self::assertSame(
            ['view', 'metadata', 'translations', 'delete', ReportTriggerFactory::ACTION_NAME],
            GeneralUtility::trimExplode(',', $backendUser->getTSConfig()['options.']['file_list.']['primaryActions'] ?? ''),
        );

        $event = $this->createFileListEvent($this->get(ResourceFactory::class)->getFileObject(self::FILE_LOGO), ['view', 'delete']);
        $this->get(AddReportActionToFileList::class)($event);
        self::assertContains(ReportTriggerFactory::ACTION_NAME, $this->listActionNames($event), 'The action is shown directly, not in the "More options" menu');
        self::assertNotContains(ReportTriggerFactory::ACTION_NAME, $this->listActionNames($event, 'secondary'));

        $this->loginBackendUser(self::BLOCKED_EDITOR);
        $event = $this->createFileListEvent($this->get(ResourceFactory::class)->getFileObject(self::FILE_LOGO), ['delete']);
        $this->get(AddReportActionToFileList::class)($event);
        self::assertFalse($this->hasListAction($event, ReportTriggerFactory::ACTION_NAME));
    }

    #[Test]
    public function fileListActionFollowsTheUserTsConfigOfTheProject(): void
    {
        $backendUser = $this->loginBackendUser(self::LIST_EDITOR);
        self::assertSame(
            ['view', 'delete'],
            GeneralUtility::trimExplode(',', $backendUser->getTSConfig()['options.']['file_list.']['primaryActions'] ?? '', true),
            'The project replaced the primary actions without the report action',
        );

        $event = $this->createFileListEvent($this->get(ResourceFactory::class)->getFileObject(self::FILE_LOGO), ['view', 'delete', 'copy']);
        $this->get(AddReportActionToFileList::class)($event);

        self::assertTrue($this->hasListAction($event, ReportTriggerFactory::ACTION_NAME));
        self::assertNotContains(ReportTriggerFactory::ACTION_NAME, $this->listActionNames($event), 'It is not shown directly');
        self::assertContains(ReportTriggerFactory::ACTION_NAME, $this->listActionNames($event, 'secondary'), 'It is in the "More options" menu');
    }

    #[Test]
    public function fileListContextMenuOffersReportingForFilesFoldersAndStorages(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $contextMenu = $this->get(ContextMenu::class);

        $fileItems = $contextMenu->getItems('sys_file', '1:/user_upload/logo.png');
        self::assertArrayHasKey('info', $fileItems, 'Core file items must still be present');
        $item = $fileItems[ReportResourceItemProvider::ITEM_NAME] ?? [];
        self::assertSame('Report a problem with this file', $item['label'] ?? null);
        self::assertSame('reportProblem', $item['callbackAction']);
        self::assertSame([
            'data-callback-module' => '@priebera/context-reporter/context-menu-actions',
            'data-context-reporter-target' => 'file',
            'data-context-reporter-uid' => '1',
        ], $item['additionalAttributes']);

        $folderItem = $contextMenu->getItems('sys_file', '1:/private/')[ReportResourceItemProvider::ITEM_NAME] ?? [];
        self::assertSame('Report a problem with this folder', $folderItem['label'] ?? null);
        self::assertSame('folder', $folderItem['additionalAttributes']['data-context-reporter-target'] ?? null);

        $storageItem = $contextMenu->getItems('sys_file_storage', '1:/', 'tree')[ReportResourceItemProvider::ITEM_NAME] ?? [];
        self::assertSame('folder', $storageItem['additionalAttributes']['data-context-reporter-target'] ?? null);
    }

    #[Test]
    public function fileContextMenuItemIsOnlyOfferedForAccessibleResources(): void
    {
        $this->loginBackendUser(self::EDITOR);
        $provider = $this->get(ReportResourceItemProvider::class);

        $provider->setContext('sys_file', '1:/user_upload/logo.png');
        self::assertTrue($provider->canHandle());
        foreach ([
            ['sys_file', '1:/private/budget.txt'],
            ['sys_file', '1:/private/'],
            ['sys_file', '0:/fileadmin/'],
            ['sys_file', 'EXT:context_reporter/ext_emconf.php'],
            ['sys_file', '1'],
            ['tt_content', '1:/user_upload/logo.png'],
        ] as [$table, $identifier]) {
            $provider->setContext($table, $identifier);
            self::assertFalse($provider->canHandle(), $table . ' ' . $identifier);
        }

        $this->loginBackendUser(self::BLOCKED_EDITOR);
        $provider->setContext('sys_file', '1:/user_upload/logo.png');
        self::assertFalse($provider->canHandle());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function dispatchRecordActions(string $table, array $row): ModifyRecordListRecordActionsEvent
    {
        $event = $this->createRecordListEvent($table, $row);
        $this->get(AddReportActionToRecordList::class)($event);
        return $event;
    }

    /**
     * @param array<string, mixed> $queryParameters
     */
    private function setRequestForRoute(string $routeIdentifier, array $queryParameters): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->createBackendRequest('/typo3/module/' . str_replace('_', '/', $routeIdentifier), 'GET')
            ->withQueryParams($queryParameters)
            ->withAttribute('route', new Route('/module/' . $routeIdentifier, ['_identifier' => $routeIdentifier]));
    }

    /**
     * @param array<'left'|'right', array<int, list<\TYPO3\CMS\Backend\Template\Components\Buttons\ButtonInterface>>> $buttons
     * @return array<'left'|'right', array<int, list<\TYPO3\CMS\Backend\Template\Components\Buttons\ButtonInterface>>>
     */
    private function dispatchButtonBar(array $buttons = []): array
    {
        return $this->get(EventDispatcherInterface::class)->dispatch($this->createButtonBarEvent($buttons))->getButtons();
    }

    /**
     * @param array<'left'|'right', array<int, list<\TYPO3\CMS\Backend\Template\Components\Buttons\ButtonInterface>>> $buttons
     */
    private function findReportButton(array $buttons): ?GenericButton
    {
        foreach ($buttons[ButtonBar::BUTTON_POSITION_RIGHT] ?? [] as $group) {
            foreach ($group as $button) {
                if ($button instanceof GenericButton && isset($button->getAttributes()['data-context-reporter-trigger'])) {
                    return $button;
                }
            }
        }
        return null;
    }
}
