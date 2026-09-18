<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Backend\ContextMenu;

use Priebera\ContextReporter\Context\Subject\FileAccess;
use Priebera\ContextReporter\Security\ReportAccessPolicy;
use TYPO3\CMS\Backend\ContextMenu\ItemProviders\ProviderInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;

/**
 * Adds "Report a problem…" to the context menu of files and folders (file
 * list in tile and list view, folder tree). Files and folders use their
 * combined identifier as context menu identifier.
 *
 * The item can be hidden with the usual user TSconfig option
 * options.contextMenu.table.sys_file.disableItems = contextReporterReport
 *
 * @internal
 */
final class ReportResourceItemProvider implements ProviderInterface
{
    use DisabledItemsTrait;

    public const ITEM_NAME = 'contextReporterReport';
    public const CALLBACK_MODULE = ReportItemProvider::CALLBACK_MODULE;
    private const TABLES = ['sys_file', 'sys_file_storage'];
    private const LABELS = 'LLL:EXT:context_reporter/Resources/Private/Language/locallang.xlf:';

    private string $table = '';
    private string $identifier = '';
    private string $context = '';
    private File|Folder|null $resource = null;

    public function __construct(
        private readonly ReportAccessPolicy $accessPolicy,
        private readonly FileAccess $fileAccess,
        private readonly IconFactory $iconFactory,
    ) {}

    public function setContext(string $table, string $identifier, string $context = ''): void
    {
        $this->table = $table;
        $this->identifier = $identifier;
        $this->context = $context;
        $this->resource = null;
    }

    /**
     * Lower than the Core file provider (100) and distinct from the record
     * provider of this extension (42), which never handles files.
     */
    public function getPriority(): int
    {
        return 41;
    }

    public function canHandle(): bool
    {
        $this->resource = null;
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!in_array($this->table, self::TABLES, true)
            || !$backendUser instanceof BackendUserAuthentication
            || !$this->accessPolicy->canReport($backendUser)
        ) {
            return false;
        }
        $this->resource = $this->fileAccess->findResource($this->identifier, $backendUser);
        return $this->resource !== null;
    }

    /**
     * @param array<string, mixed> $items
     * @return array<string, mixed>
     */
    public function addItems(array $items): array
    {
        if ($this->resource === null || $this->isItemDisabled(self::ITEM_NAME, $this->table, $this->context)) {
            return $items;
        }
        $attributes = ['data-callback-module' => self::CALLBACK_MODULE];
        if ($this->resource instanceof File) {
            $attributes['data-context-reporter-target'] = 'file';
            $attributes['data-context-reporter-uid'] = (string)$this->resource->getUid();
        } else {
            $attributes['data-context-reporter-target'] = 'folder';
        }
        if ($items !== []) {
            $items['contextReporterDivider'] = [
                'type' => 'divider',
                'label' => '',
                'icon' => '',
                'additionalAttributes' => [],
                'callbackAction' => '',
            ];
        }
        $label = $this->resource instanceof File ? 'contextMenu.reportFile' : 'contextMenu.reportFolder';
        $items[self::ITEM_NAME] = [
            'type' => 'item',
            'label' => htmlspecialchars($this->getLanguageService()->sL(self::LABELS . $label)),
            'icon' => $this->iconFactory->getIcon('context-reporter-report', IconSize::SMALL)->render('inline'),
            'additionalAttributes' => $attributes,
            'callbackAction' => 'reportProblem',
        ];
        return $items;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
