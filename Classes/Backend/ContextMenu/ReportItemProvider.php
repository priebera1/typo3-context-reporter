<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Backend\ContextMenu;

use Priebera\ContextReporter\Context\Subject\RecordAccess;
use Priebera\ContextReporter\Context\Tca\TcaInspector;
use Priebera\ContextReporter\Security\ReportAccessPolicy;
use TYPO3\CMS\Backend\ContextMenu\ItemProviders\ProviderInterface;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Adds "Report a problem…" to the context menu of pages and TCA records
 * (page tree, List module, Page module, ...).
 *
 * The item can be hidden per table with the usual user TSconfig option
 * options.contextMenu.table.<table>.disableItems = contextReporterReport
 *
 * @internal
 */
final class ReportItemProvider implements ProviderInterface
{
    use DisabledItemsTrait;

    public const ITEM_NAME = 'contextReporterReport';
    public const CALLBACK_MODULE = '@priebera/context-reporter/context-menu-actions';
    private const LABELS = 'LLL:EXT:context_reporter/Resources/Private/Language/locallang.xlf:';

    private string $table = '';
    private string $identifier = '';
    private string $context = '';

    public function __construct(
        private readonly ReportAccessPolicy $accessPolicy,
        private readonly TcaInspector $tca,
        private readonly IconFactory $iconFactory,
    ) {}

    public function setContext(string $table, string $identifier, string $context = ''): void
    {
        $this->table = $table;
        $this->identifier = $identifier;
        $this->context = $context;
    }

    /**
     * Lower than the Core record (60) and page (100) providers, so their
     * items come first, and distinct from EXT:impexp (50).
     */
    public function getPriority(): int
    {
        return 42;
    }

    public function canHandle(): bool
    {
        return $this->tca->hasTable($this->table)
            && !in_array($this->table, RecordAccess::DENIED_TABLES, true)
            && preg_match('/^[1-9]\d{0,9}$/D', $this->identifier) === 1
            && $this->accessPolicy->canReport($GLOBALS['BE_USER'] ?? null);
    }

    /**
     * @param array<string, mixed> $items
     * @return array<string, mixed>
     */
    public function addItems(array $items): array
    {
        if ($this->isItemDisabled(self::ITEM_NAME, $this->table, $this->context)) {
            return $items;
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
        $label = $this->table === 'pages' ? 'contextMenu.reportPage' : 'contextMenu.reportRecord';
        $items[self::ITEM_NAME] = [
            'type' => 'item',
            'label' => htmlspecialchars($this->getLanguageService()->sL(self::LABELS . $label)),
            'icon' => $this->iconFactory->getIcon('context-reporter-report', IconSize::SMALL)->render('inline'),
            'additionalAttributes' => [
                'data-callback-module' => self::CALLBACK_MODULE,
            ],
            'callbackAction' => 'reportProblem',
        ];
        return $items;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
