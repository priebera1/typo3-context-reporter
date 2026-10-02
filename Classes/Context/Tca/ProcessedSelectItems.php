<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Tca;

use TYPO3\CMS\Core\DataHandling\ItemProcessingService;

/**
 * The items of a select field as the editing form offers them for a record:
 * the static TCA items processed by the field's item function, e.g. the
 * columns of the backend layout for "tt_content.colPos" or the backend
 * layouts for "pages.backend_layout". The item functions of extensions are
 * included, so the result is what TYPO3 actually offers.
 *
 * ItemProcessingService runs "itemsProcFunc" on TYPO3 v13 and also
 * "itemsProcessors" on TYPO3 v14. It returns SelectItem objects on TYPO3 v13
 * and arrays on TYPO3 v14; both are read as array. TSconfig (addItems,
 * removeItems, keepItems) is not applied, like in the Page module.
 *
 * @internal
 */
final readonly class ProcessedSelectItems
{
    public function __construct(
        private ItemProcessingService $itemProcessingService,
    ) {}

    /**
     * @param array<string, mixed> $row The record, workspace overlaid
     * @param int $realPid The page the record is stored on (the page itself for pages)
     * @return list<array{value: string, label: string}>|null Null when the field has no item function
     */
    public function getItems(string $table, string $field, array $row, int $realPid): ?array
    {
        $config = $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? null;
        if (!is_array($config) || ($config['type'] ?? '') !== 'select' || !$this->hasItemFunction($config)) {
            return null;
        }
        $items = $this->itemProcessingService->getProcessingItems($table, $realPid, $field, $row, $config, is_array($config['items'] ?? null) ? $config['items'] : []);
        return self::normalize(is_array($items) ? $items : []);
    }

    /**
     * @param array<array-key, mixed> $items TCA items, SelectItem objects or arrays
     * @return list<array{value: string, label: string}>
     */
    public static function normalize(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item) && !$item instanceof \ArrayAccess) {
                continue;
            }
            $value = $item['value'] ?? null;
            $label = $item['label'] ?? null;
            if (is_scalar($value) && !is_bool($value)) {
                $normalized[] = ['value' => (string)$value, 'label' => is_string($label) ? $label : ''];
            }
        }
        return $normalized;
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private function hasItemFunction(array $config): bool
    {
        if (is_string($config['itemsProcFunc'] ?? null) && $config['itemsProcFunc'] !== '') {
            return true;
        }
        // Item processors exist since TYPO3 v14
        return !empty($config['itemsProcessors']) && method_exists($this->itemProcessingService, 'processItems');
    }
}
