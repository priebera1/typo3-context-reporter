<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Subject;

use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\Tca\TcaInspector;
use Priebera\ContextReporter\Domain\SubjectType;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The language the reporter was working in: the language of the record or
 * page translation, or the language selected for the page in a page tree
 * module (Page, Preview, Records, or modules of other extensions such as the
 * Visual Editor).
 *
 * Where the selection is stored differs between the TYPO3 branches:
 *
 * - TYPO3 v13 keeps it in the module data of each module, as "language"
 *   (one ID; Page and Preview module) or "languages" (a list; e.g. the
 *   Visual Editor).
 * - TYPO3 v14 keeps it per page for all page tree modules in the user settings
 *   ("pageLanguages", written by the page context when the editor changes the
 *   language) and falls back to the module data ("languages", or the former
 *   "language"), like its page context does.
 *
 * Several selected languages (side by side views) are reported like the
 * Preview module of TYPO3 v14 shows them: in the one selected translation, or
 * in the default language when none or more than one translation is selected.
 * A translation is only used when the page's site has the language, the
 * reporter may use it and the page is translated into it in the reporter's
 * workspace; otherwise the report uses the default language, as the modules do.
 *
 * @internal
 */
final readonly class SubjectLanguageResolver
{
    private const MAX_SELECTED_LANGUAGES = 50;

    public function __construct(
        private TcaInspector $tca,
        private ModuleProvider $moduleProvider,
        private RecordAccess $recordAccess,
        private SiteFinder $siteFinder,
    ) {}

    public function resolve(CollectionScope $scope): ?int
    {
        $subject = $scope->subject;
        if ($subject->type === SubjectType::Record) {
            $field = $this->tca->getLanguageField($subject->table);
            if ($field === '') {
                return null;
            }
            if ($subject->isNewRecord) {
                $default = $scope->request->location->defaultValues[$subject->table][$field] ?? '0';
                return preg_match('/^-?\d{1,5}$/D', $default) ? (int)$default : 0;
            }
            $value = $subject->record[$field] ?? null;
            return is_numeric($value) ? (int)$value : null;
        }
        if ($subject->type !== SubjectType::Page) {
            return null;
        }

        // A page translation, e.g. opened in the editing form
        $field = $this->tca->getLanguageField('pages');
        $pageLanguage = $field !== '' ? ($subject->record[$field] ?? null) : null;
        if (is_numeric($pageLanguage) && (int)$pageLanguage > 0) {
            return (int)$pageLanguage;
        }

        $selection = $this->findSelectedLanguages($scope);
        if ($selection === null) {
            return 0;
        }
        $languageId = self::getPrimaryLanguageId($selection);
        return $languageId > 0 && $this->isAvailable($languageId, $subject->pageUid, $scope->backendUser) ? $languageId : 0;
    }

    /**
     * @return list<int>|null Null when the reporter was not in a page tree module or did not select a language
     */
    private function findSelectedLanguages(CollectionScope $scope): ?array
    {
        $moduleIdentifier = $scope->route !== null && $scope->route->moduleIdentifier !== ''
            ? $scope->route->moduleIdentifier
            : $scope->request->location->moduleIdentifier;
        if ($moduleIdentifier === '') {
            return null;
        }
        $module = $this->moduleProvider->getModule($moduleIdentifier, $scope->backendUser);
        if ($module === null || $module->getNavigationComponent() !== SubjectResolver::PAGE_TREE_COMPONENT) {
            return null;
        }

        $backendUser = $scope->backendUser;
        $pageLanguages = $backendUser->uc['pageLanguages'][$scope->subject->pageUid] ?? null;
        if (is_array($pageLanguages)) {
            return self::toLanguageIds($pageLanguages);
        }
        $moduleData = $backendUser->getModuleData($module->getIdentifier());
        if (!is_array($moduleData)) {
            return null;
        }
        if (is_array($moduleData['languages'] ?? null)) {
            return self::toLanguageIds($moduleData['languages']);
        }
        return array_key_exists('language', $moduleData) ? self::toLanguageIds([$moduleData['language']]) : null;
    }

    private function isAvailable(int $languageId, int $pageUid, BackendUserAuthentication $backendUser): bool
    {
        if (!$backendUser->checkLanguageAccess($languageId)) {
            return false;
        }
        try {
            $this->siteFinder->getSiteByPageId($pageUid)->getLanguageById($languageId);
        } catch (\Throwable) {
            return false;
        }
        return $this->recordAccess->hasPageTranslation($pageUid, $languageId, $backendUser);
    }

    /**
     * The one selected translation, otherwise the default language.
     *
     * @param list<int> $languageIds
     */
    private static function getPrimaryLanguageId(array $languageIds): int
    {
        $translations = array_values(array_filter($languageIds, static fn(int $id): bool => $id > 0));
        return count($translations) === 1 ? $translations[0] : 0;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<int>
     */
    private static function toLanguageIds(array $values): array
    {
        $languageIds = [];
        foreach (array_slice($values, 0, self::MAX_SELECTED_LANGUAGES) as $value) {
            if (is_int($value) || (is_string($value) && preg_match('/^-?\d{1,5}$/D', $value))) {
                $languageIds[] = (int)$value;
            }
        }
        return array_values(array_unique($languageIds));
    }
}
