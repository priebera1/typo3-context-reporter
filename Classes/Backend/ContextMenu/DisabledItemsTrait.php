<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Backend\ContextMenu;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Evaluates options.contextMenu.table.<table>[.<context>].disableItems, like
 * the Core item providers do.
 *
 * @internal
 */
trait DisabledItemsTrait
{
    private function isItemDisabled(string $itemName, string $table, string $context): bool
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return true;
        }
        $tableOptions = $backendUser->getTSConfig()['options.']['contextMenu.']['table.'][$table . '.'] ?? [];
        $disabled = $context !== ''
            ? ($tableOptions[$context . '.']['disableItems'] ?? '')
            : ($tableOptions['disableItems'] ?? '');
        return in_array($itemName, GeneralUtility::trimExplode(',', is_string($disabled) ? $disabled : '', true), true);
    }
}
