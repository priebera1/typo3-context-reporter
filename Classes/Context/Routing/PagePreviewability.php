<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Routing;

use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Whether TYPO3 offers to view a page on the website, as its "View" button
 * decides it: page TSconfig "TCEMAIN.preview.disableButtonForDokType", by
 * default folders and spacers on TYPO3 v13 and the page type configuration
 * ("isViewable") on TYPO3 v14. Pages deleted in the workspace are not viewable.
 *
 * TYPO3 13.4.15 and 14.3 decide this in PreviewUriBuilder::isPreviewable().
 * Earlier TYPO3 13.4 versions apply the same TSconfig rule in their
 * controllers, which isPreviewableDoktype() repeats.
 *
 * @internal
 */
final readonly class PagePreviewability
{
    /** Page types TYPO3 v13 does not view unless TSconfig says otherwise */
    private const DEFAULT_EXCLUDED_DOKTYPES = [PageRepository::DOKTYPE_SYSFOLDER, PageRepository::DOKTYPE_SPACER];

    /**
     * @param array<string, mixed> $page Workspace overlaid page row (default language or translation)
     */
    public function isPreviewable(array $page): bool
    {
        if (method_exists(PreviewUriBuilder::class, 'isPreviewable')) {
            return PreviewUriBuilder::create($page)->isPreviewable();
        }
        $parentField = (string)($GLOBALS['TCA']['pages']['ctrl']['transOrigPointerField'] ?? '');
        $pageUid = $parentField !== '' && (int)($page[$parentField] ?? 0) > 0 ? (int)$page[$parentField] : (int)($page['uid'] ?? 0);
        if ($pageUid <= 0) {
            return false;
        }
        $previewTsConfig = BackendUtility::getPagesTSconfig($pageUid)['TCEMAIN.']['preview.'] ?? [];
        return self::isPreviewableDoktype((int)($page['doktype'] ?? 0), is_array($previewTsConfig) ? $previewTsConfig : []);
    }

    /**
     * @param array<array-key, mixed> $previewTsConfig "TCEMAIN.preview." of the page TSconfig
     */
    public static function isPreviewableDoktype(int $doktype, array $previewTsConfig): bool
    {
        if ($doktype <= 0) {
            return false;
        }
        $excluded = isset($previewTsConfig['disableButtonForDokType'])
            ? GeneralUtility::intExplode(',', (string)$previewTsConfig['disableButtonForDokType'], true)
            : self::DEFAULT_EXCLUDED_DOKTYPES;
        return !in_array($doktype, $excluded, true);
    }
}
