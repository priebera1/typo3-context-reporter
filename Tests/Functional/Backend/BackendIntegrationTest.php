<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Backend\ContextMenu\ReportItemProvider;
use Priebera\ContextReporter\Backend\EventListener\AddReportButtonToRecordEditor;
use Priebera\ContextReporter\Backend\ToolbarItem\ReportToolbarItem;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use TYPO3\CMS\Backend\ContextMenu\ContextMenu;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\ButtonInterface;
use TYPO3\CMS\Backend\Template\Components\Buttons\GenericButton;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemsRegistry;

final class BackendIntegrationTest extends AbstractContextReporterTestCase
{
    use ListActionEvents;

    #[Test]
    public function contextMenuOffersReportingForPagesAndRecordsAfterTheCoreItems(): void
    {
        $this->loginBackendUser(self::EDITOR);
        $contextMenu = $this->get(ContextMenu::class);

        $pageItems = $contextMenu->getItems('pages', '2', 'tree');
        self::assertArrayHasKey(ReportItemProvider::ITEM_NAME, $pageItems);
        self::assertSame(ReportItemProvider::ITEM_NAME, array_key_last($pageItems));
        self::assertGreaterThan(2, count($pageItems), 'Core page items must still be present');
        $item = $pageItems[ReportItemProvider::ITEM_NAME];
        self::assertSame('item', $item['type']);
        self::assertSame('Problem mit dieser Seite melden', $item['label']);
        self::assertSame('reportProblem', $item['callbackAction']);
        self::assertSame(['data-callback-module' => '@priebera/context-reporter/context-menu-actions'], $item['additionalAttributes']);
        self::assertStringContainsString('<svg', $item['icon']);

        $recordItems = $contextMenu->getItems('tt_content', '10');
        self::assertSame('Problem mit diesem Datensatz melden', $recordItems[ReportItemProvider::ITEM_NAME]['label'] ?? null);
        self::assertArrayHasKey('info', $recordItems, 'Core record items must still be present');
    }

    #[Test]
    public function contextMenuItemIsNotOfferedForFilesOrWhenReportingIsDisabled(): void
    {
        $this->loginBackendUser(self::EDITOR);
        $provider = $this->get(ReportItemProvider::class);

        $provider->setContext('sys_file', '1:/user_upload/image.png');
        self::assertFalse($provider->canHandle());
        $provider->setContext('tt_content', 'NEW123');
        self::assertFalse($provider->canHandle());
        $provider->setContext('tx_unknown_table', '1');
        self::assertFalse($provider->canHandle());
        $provider->setContext('tt_content', '10');
        self::assertTrue($provider->canHandle());

        $this->loginBackendUser(self::BLOCKED_EDITOR);
        self::assertFalse($provider->canHandle());
    }

    #[Test]
    public function contextMenuItemCanBeDisabledWithTsConfig(): void
    {
        $backendUser = $this->loginBackendUser(self::ADMIN);
        $backendUser->user['TSconfig'] = 'options.contextMenu.table.pages.tree.disableItems = contextReporterReport';
        $backendUser->fetchGroupData();

        $provider = $this->get(ReportItemProvider::class);
        $provider->setContext('pages', '2', 'tree');
        self::assertSame(['info' => ['type' => 'item']], $provider->addItems(['info' => ['type' => 'item']]));

        $provider->setContext('pages', '2', '');
        self::assertArrayHasKey(ReportItemProvider::ITEM_NAME, $provider->addItems([]));
    }

    #[Test]
    public function recordEditorGetsAReportButtonWithTheOpenRecord(): void
    {
        $this->loginBackendUser(self::EDITOR);
        $this->setRequestForRoute('record_edit', ['edit' => ['tt_content' => ['10' => 'edit']]]);

        $button = $this->findReportButton($this->dispatchButtonBarEvent());

        self::assertNotNull($button);
        self::assertSame('Problem melden', $button->getLabel());
        self::assertTrue($button->getShowLabelText());
        self::assertSame([
            'type' => 'button',
            'data-context-reporter-trigger' => 'formEngine',
            'data-context-reporter-table' => 'tt_content',
            'data-context-reporter-uid' => '10',
        ], $button->getAttributes());
    }

    #[Test]
    public function recordEditorWithSeveralOrNewRecordsGetsAButtonWithoutExplicitTarget(): void
    {
        $this->loginBackendUser(self::EDITOR);

        $this->setRequestForRoute('record_edit', ['edit' => ['tt_content' => ['10,12' => 'edit']]]);
        self::assertSame(['type' => 'button', 'data-context-reporter-trigger' => 'formEngine'], $this->findReportButton($this->dispatchButtonBarEvent())?->getAttributes());

        $this->setRequestForRoute('record_edit', ['edit' => ['tt_content' => ['2' => 'new']]]);
        self::assertSame(['type' => 'button', 'data-context-reporter-trigger' => 'formEngine'], $this->findReportButton($this->dispatchButtonBarEvent())?->getAttributes());
    }

    #[Test]
    public function reportButtonIsNotAddedToOtherViewsTwiceOrForBlockedUsers(): void
    {
        $this->loginBackendUser(self::EDITOR);
        $this->setRequestForRoute('web_layout', ['id' => '2']);
        self::assertNull($this->findReportButton($this->dispatchButtonBarEvent()));

        $this->setRequestForRoute('record_edit', []);
        self::assertNull($this->findReportButton($this->dispatchButtonBarEvent()));

        $this->setRequestForRoute('record_edit', ['edit' => ['tt_content' => ['10' => 'edit']]]);
        $buttons = $this->dispatchButtonBarEvent();
        $listener = $this->get(AddReportButtonToRecordEditor::class);
        $event = $this->createButtonBarEvent($buttons);
        $listener($event);
        self::assertCount(1, $event->getButtons()[ButtonBar::BUTTON_POSITION_RIGHT][AddReportButtonToRecordEditor::BUTTON_GROUP]);

        $this->loginBackendUser(self::BLOCKED_EDITOR);
        $this->setRequestForRoute('record_edit', ['edit' => ['tt_content' => ['10' => 'edit']]]);
        self::assertNull($this->findReportButton($this->dispatchButtonBarEvent()));
    }

    #[Test]
    public function toolbarItemIsRegisteredAndRespectsTheAccessPolicy(): void
    {
        $this->loginBackendUser(self::EDITOR);
        $items = array_filter(
            $this->get(ToolbarItemsRegistry::class)->getToolbarItems(),
            static fn(object $item): bool => $item instanceof ReportToolbarItem,
        );
        self::assertCount(1, $items);

        $toolbarItem = $this->get(ReportToolbarItem::class);
        self::assertTrue($toolbarItem->checkAccess());
        self::assertFalse($toolbarItem->hasDropDown());
        $toolbarItem->setRequest($this->createBackendRequest('/typo3/main', 'GET')->withAttribute('route', new Route('/main', ['_identifier' => 'main', 'packageName' => 'typo3/cms-backend'])));
        $markup = $toolbarItem->getItem();
        self::assertStringContainsString('data-context-reporter-trigger="toolbar"', $markup);
        self::assertStringContainsString('aria-haspopup="dialog"', $markup);
        self::assertStringContainsString('Problem melden', $markup);

        $this->loginBackendUser(self::BLOCKED_EDITOR);
        self::assertFalse($toolbarItem->checkAccess());
    }

    /**
     * @param array<string, mixed> $queryParameters
     */
    private function setRequestForRoute(string $routeIdentifier, array $queryParameters): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->createBackendRequest('/typo3/' . str_replace('_', '/', $routeIdentifier), 'GET')
            ->withQueryParams($queryParameters)
            ->withAttribute('route', new Route('/' . $routeIdentifier, ['_identifier' => $routeIdentifier]));
    }

    /**
     * @return array<'left'|'right', array<int, list<ButtonInterface>>>
     */
    private function dispatchButtonBarEvent(): array
    {
        $event = $this->createButtonBarEvent();
        $this->get(AddReportButtonToRecordEditor::class)($event);
        return $event->getButtons();
    }

    /**
     * @param array<'left'|'right', array<int, list<ButtonInterface>>> $buttons
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
