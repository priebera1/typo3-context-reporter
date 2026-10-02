<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Priebera\ContextReporter\Context\ServerTime;
use Priebera\ContextReporter\Context\Subject\RecordAccess;
use Priebera\ContextReporter\Context\Tca\TcaInspector;
use Priebera\ContextReporter\Context\Visibility\VisibilityEvaluator;
use Priebera\ContextReporter\Domain\SubjectType;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\PageTranslationVisibility;

/**
 * The stored settings that decide whether TYPO3 shows the reported page or
 * record on the website: hidden, start and end time, frontend user groups,
 * "hidden in menus", restrictions parent pages pass on to their subpages,
 * translations and the workspace state.
 *
 * These are facts, not a statement about the rendered website: templates,
 * caches, extensions and the visitor decide the rest. Everything is read
 * within the reporter's access, like the other collectors: parent pages up to
 * the first page they cannot access, languages they may use and their
 * current workspace. The values are the ones TYPO3 shows the reporter in the
 * page tree and the Page module anyway; "Extend to subpages" is only read with
 * permission for that field.
 *
 * @internal
 */
#[AsTaggedItem(priority: 35)]
final readonly class VisibilityCollector implements ContextCollectorInterface
{
    private const INHERITANCE_FIELD = 'extendToSubpages';
    private const MENU_FIELD = 'nav_hide';
    private const TRANSLATION_BEHAVIOUR_FIELD = 'l18n_cfg';
    private const MAX_PARENT_DEPTH = 20;
    private const MAX_LANGUAGES = 30;
    private const MAX_FRONTEND_GROUPS = 10;
    private const MAX_GROUP_TITLE_LENGTH = 100;

    public function __construct(
        private TcaInspector $tca,
        private RecordAccess $recordAccess,
        private VisibilityEvaluator $evaluator,
        private SiteFinder $siteFinder,
        private Context $context,
    ) {}

    public function getSectionKey(): string
    {
        return 'visibility';
    }

    public function collect(CollectionScope $scope): array
    {
        $subject = $scope->subject;
        $isRecord = $subject->type === SubjectType::Record && !$subject->isNewRecord && $subject->record !== [];
        $isPage = $subject->type === SubjectType::Page && $subject->record !== [];
        if (!$isRecord && !$isPage) {
            return [];
        }
        $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        $workspace = (int)$this->context->getPropertyFromAspect('workspace', 'id', 0);
        $backendUser = $scope->backendUser;
        $groupTitles = new \ArrayObject();

        $data = [
            'evaluatedAt' => ServerTime::format($now),
            'subject' => $this->describe($subject->table, $subject->record, $now, $workspace, $groupTitles)
                + ($isPage ? $this->describeTranslationBehaviour($subject->record, $backendUser) : []),
        ];

        // The page the settings of the subject depend on: the page of a record,
        // or the default language page of a page translation
        $page = $isRecord ? $subject->page : $this->findDefaultLanguagePage($subject->record, $backendUser);
        if ($page !== []) {
            $data['page'] = ['uid' => (int)($page['uid'] ?? 0), 'title' => (string)($page['title'] ?? '')]
                + $this->describe('pages', $page, $now, $workspace, $groupTitles)
                + $this->describeTranslationBehaviour($page, $backendUser);
        }
        $basePage = $page !== [] ? $page : ($isPage ? $subject->record : []);

        if ($basePage !== [] && $this->mayRead('pages', self::INHERITANCE_FIELD, $backendUser)) {
            $data['parentPages'] = $this->describeParentPages($basePage, $now, $backendUser, $groupTitles);
        }
        $translations = $this->describeTranslations($scope, $basePage, $now, $groupTitles);
        if ($translations !== []) {
            $data['translations'] = $translations;
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $row
     * @param \ArrayObject<int, string> $groupTitles
     * @return array<string, mixed>
     */
    private function describe(string $table, array $row, int $now, int $workspace, \ArrayObject $groupTitles, bool $withState = true): array
    {
        $enableFields = $this->tca->getEnableFields($table);
        $visibility = $this->evaluator->evaluate($row, $enableFields, $now);
        $facts = ['reasons' => $visibility->reasons];
        if ($visibility->hidden) {
            $facts['hidden'] = true;
        }
        if ($visibility->startTime > 0) {
            $facts['starttime'] = ServerTime::format($visibility->startTime);
        }
        if ($visibility->endTime > 0) {
            $facts['endtime'] = ServerTime::format($visibility->endTime);
        }
        if ($visibility->frontendGroups !== []) {
            $facts['frontendGroups'] = $this->describeGroups($table, $enableFields->frontendGroups, $visibility->frontendGroups, $groupTitles);
            $notListed = count($visibility->frontendGroups) - self::MAX_FRONTEND_GROUPS;
            if ($notListed > 0) {
                $facts['frontendGroupsNotListed'] = $notListed;
            }
        }
        if (!$withState) {
            return $facts;
        }
        if ($table === 'pages' && $this->tca->hasColumn('pages', self::MENU_FIELD) && (bool)($row[self::MENU_FIELD] ?? false)) {
            $facts['hiddenInMenu'] = true;
        }
        $workspaceState = $this->tca->isWorkspaceAware($table) ? $this->evaluator->getWorkspaceState($row, $workspace) : null;
        if ($workspaceState !== null) {
            $facts['workspaceState'] = $workspaceState;
        }
        return $facts;
    }

    /**
     * "Translation behaviour" (l18n_cfg) of a default language page, which
     * applies to all its translations: "Hide default language of page" and
     * "Hide page if no translation for the current language exists". The
     * latter is inverted by $GLOBALS['TYPO3_CONF_VARS']['FE']['hidePagesIfNotTranslatedByDefault'],
     * which PageTranslationVisibility applies. Only read with permission for
     * the field and only reported when a setting is active.
     *
     * @param array<string, mixed> $page
     * @return array{translationBehaviour?: array{hideDefaultLanguage?: true, hideIfNotTranslated?: true}}
     */
    private function describeTranslationBehaviour(array $page, BackendUserAuthentication $backendUser): array
    {
        if ($this->getLanguage('pages', $page) !== 0 || !$this->mayRead('pages', self::TRANSLATION_BEHAVIOUR_FIELD, $backendUser)) {
            return [];
        }
        $settings = new PageTranslationVisibility((int)($page[self::TRANSLATION_BEHAVIOUR_FIELD] ?? 0));
        $behaviour = array_filter([
            'hideDefaultLanguage' => $settings->shouldBeHiddenInDefaultLanguage(),
            'hideIfNotTranslated' => $settings->shouldHideTranslationIfNoTranslatedRecordExists(),
        ]);
        return $behaviour !== [] ? ['translationBehaviour' => $behaviour] : [];
    }

    /**
     * Parent pages with "Extend to subpages" whose settings TYPO3 applies to
     * the page. The walk stops at the first parent page the reporter cannot
     * access, which is recorded as an incomplete check.
     *
     * @param array<string, mixed> $page
     * @param \ArrayObject<int, string> $groupTitles
     * @return array{checkedUpToRoot: bool, restricting: list<array<string, mixed>>}
     */
    private function describeParentPages(array $page, int $now, BackendUserAuthentication $backendUser, \ArrayObject $groupTitles): array
    {
        $enableFields = $this->tca->getEnableFields('pages');
        $restricting = [];
        $parentUid = (int)($page['pid'] ?? 0);
        for ($depth = 0; $parentUid > 0 && $depth < self::MAX_PARENT_DEPTH; $depth++) {
            $parent = $this->recordAccess->findPage($parentUid, $backendUser);
            if ($parent === null) {
                break;
            }
            $visibility = $this->evaluator->evaluate($parent, $enableFields, $now);
            if ($this->evaluator->restrictsSubpages($parent, $visibility, self::INHERITANCE_FIELD)) {
                $restricting[] = ['uid' => (int)($parent['uid'] ?? 0), 'title' => (string)($parent['title'] ?? '')]
                    + $this->describe('pages', $parent, $now, 0, $groupTitles, false);
            }
            $parentUid = (int)($parent['pid'] ?? 0);
        }
        return ['checkedUpToRoot' => $parentUid === 0, 'restricting' => $restricting];
    }

    /**
     * Translations of the page and, for a record in the default language, of
     * the record, in the languages of the site the reporter may use.
     *
     * @param array<string, mixed> $basePage Default language page
     * @param \ArrayObject<int, string> $groupTitles
     * @return list<array<string, mixed>>
     */
    private function describeTranslations(CollectionScope $scope, array $basePage, int $now, \ArrayObject $groupTitles): array
    {
        $subject = $scope->subject;
        $backendUser = $scope->backendUser;
        $pageUid = (int)($basePage['uid'] ?? 0);
        $subjectLanguage = $this->getLanguage($subject->table, $subject->record);
        // A page translation is itself the translation; its settings are the subject's
        if ($pageUid <= 0 || ($subject->type === SubjectType::Page && $subjectLanguage > 0)) {
            return [];
        }
        try {
            $siteLanguages = $this->siteFinder->getSiteByPageId($pageUid)->getAllLanguages();
        } catch (\Throwable) {
            return [];
        }

        $pageTranslations = $this->recordAccess->findTranslations('pages', $pageUid, $backendUser);
        $recordTranslations = null;
        if ($subject->type === SubjectType::Record && $subjectLanguage === 0 && $this->tca->getTranslationSourceField($subject->table) !== '') {
            $recordTranslations = $this->recordAccess->findTranslations($subject->table, $subject->uid, $backendUser);
        }

        $translations = [];
        foreach ($siteLanguages as $siteLanguage) {
            $languageId = $siteLanguage->getLanguageId();
            if ($languageId <= 0
                || !$backendUser->checkLanguageAccess($languageId)
                // A translated record only concerns its own language
                || ($subjectLanguage > 0 && $languageId !== $subjectLanguage)
            ) {
                continue;
            }
            $entry = ['languageId' => $languageId, 'title' => $siteLanguage->getTitle()];
            if (!$siteLanguage->isEnabled()) {
                $entry['enabled'] = false;
            }
            $entry['page'] = $this->describeTranslation('pages', $pageTranslations[$languageId] ?? null, $now, $groupTitles);
            if ($recordTranslations !== null) {
                $entry['record'] = $this->describeTranslation($subject->table, $recordTranslations[$languageId] ?? null, $now, $groupTitles);
            }
            $translations[] = $entry;
            if (count($translations) >= self::MAX_LANGUAGES) {
                break;
            }
        }
        return $translations;
    }

    /**
     * @param array<string, mixed>|null $row
     * @param \ArrayObject<int, string> $groupTitles
     * @return array<string, mixed>
     */
    private function describeTranslation(string $table, ?array $row, int $now, \ArrayObject $groupTitles): array
    {
        if ($row === null) {
            return ['exists' => false];
        }
        return ['exists' => true] + $this->describe($table, $row, $now, 0, $groupTitles, false);
    }

    /**
     * Group titles as the page tree tooltip shows them; -1 and -2 are the
     * "Hide at login" and "Show at any login" options.
     *
     * @param list<int> $groups
     * @param \ArrayObject<int, string> $groupTitles
     * @return list<array{id: int, title: string}>
     */
    private function describeGroups(string $table, string $field, array $groups, \ArrayObject $groupTitles): array
    {
        $described = [];
        foreach (array_slice($groups, 0, self::MAX_FRONTEND_GROUPS) as $group) {
            if (!$groupTitles->offsetExists($group)) {
                $title = $group < 0
                    ? $this->tca->getItemLabel($table, $field, (string)$group)
                    : (string)(BackendUtility::getRecord('fe_groups', $group, 'title')['title'] ?? '');
                $title = trim((string)preg_replace('/\s+/', ' ', $title));
                $groupTitles[$group] = mb_strlen($title) > self::MAX_GROUP_TITLE_LENGTH ? mb_substr($title, 0, self::MAX_GROUP_TITLE_LENGTH) . '…' : $title;
            }
            $described[] = ['id' => $group, 'title' => (string)($groupTitles[$group] ?? '')];
        }
        return $described;
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function findDefaultLanguagePage(array $page, BackendUserAuthentication $backendUser): array
    {
        if ($this->getLanguage('pages', $page) <= 0) {
            return [];
        }
        $parentField = $this->tca->getTranslationSourceField('pages');
        $defaultPageUid = $parentField !== '' ? (int)($page[$parentField] ?? 0) : 0;
        return $defaultPageUid > 0 ? ($this->recordAccess->findPage($defaultPageUid, $backendUser) ?? []) : [];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function getLanguage(string $table, array $row): int
    {
        $field = $this->tca->getLanguageField($table);
        return $field !== '' ? (int)($row[$field] ?? 0) : 0;
    }

    private function mayRead(string $table, string $field, BackendUserAuthentication $backendUser): bool
    {
        return $this->tca->hasColumn($table, $field)
            && (!$this->tca->isExcludeField($table, $field) || $backendUser->isAdmin() || $backendUser->check('non_exclude_fields', $table . ':' . $field));
    }
}
