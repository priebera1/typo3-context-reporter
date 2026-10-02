<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Priebera\ContextReporter\Context\Subject\RecordAccess;
use Priebera\ContextReporter\Context\Tca\ProcessedSelectItems;
use Priebera\ContextReporter\Context\Tca\TcaInspector;
use Priebera\ContextReporter\Domain\SubjectType;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Where content is placed: the column of a reported content element and
 * whether the backend layout of its page has that column, the backend layout
 * of the page and where it is set, and "Show content from page" in both
 * directions. For reported pages and content elements.
 *
 * The columns come from TYPO3 itself (the item function of the column field,
 * see ProcessedSelectItems), including the columns extensions such as
 * container extensions offer; the layout selection is not rebuilt. Whether
 * the website shows an element in a column depends on the templates and is
 * not decided here. The backend layout fields are only read with permission
 * for them, and pages are only named when the reporter may access them.
 *
 * @internal
 */
#[AsTaggedItem(priority: 37)]
final readonly class PlacementCollector implements ContextCollectorInterface
{
    private const CONTENT_TABLE = 'tt_content';
    private const COLUMN_FIELD = 'colPos';
    private const LAYOUT_FIELD = 'backend_layout';
    private const SUBPAGES_LAYOUT_FIELD = 'backend_layout_next_level';
    private const CONTENT_FROM_PAGE_FIELD = 'content_from_pid';
    /** Stored value for "None" */
    private const NO_LAYOUT = '-1';
    private const MAX_PARENT_DEPTH = 20;
    private const MAX_LAYOUT_COLUMNS = 30;
    private const MAX_LISTED_PAGES = 10;
    private const MAX_CHECKED_PAGES = 100;

    public function __construct(
        private TcaInspector $tca,
        private RecordAccess $recordAccess,
        private ProcessedSelectItems $selectItems,
    ) {}

    public function getSectionKey(): string
    {
        return 'placement';
    }

    public function collect(CollectionScope $scope): array
    {
        $subject = $scope->subject;
        if ($subject->isNewRecord || $subject->record === []) {
            return [];
        }
        $data = [];
        if ($subject->type === SubjectType::Page) {
            $page = $this->getDefaultLanguagePage($subject->record, $scope->backendUser);
        } elseif ($subject->type === SubjectType::Record && $subject->table === self::CONTENT_TABLE && $subject->hasPage()) {
            $page = $subject->page;
            $data = $this->describeColumn($subject->record, $subject->pageUid);
        } else {
            return [];
        }
        if ($page === []) {
            return $data;
        }

        $layout = $this->describeBackendLayout($page, $scope->backendUser);
        if ($layout !== []) {
            $data['backendLayout'] = $layout;
        }
        $contentFromPage = $this->describeContentFromPage($page, $scope->backendUser);
        if ($contentFromPage !== []) {
            $data['contentFromPage'] = $contentFromPage;
        }
        $shownOn = $this->describePagesShowingTheContent((int)($page['uid'] ?? 0), $scope->backendUser);
        if ($shownOn !== []) {
            $data['contentShownOn'] = $shownOn;
        }
        return $data;
    }

    /**
     * The column of a content element and the columns TYPO3 offers for it.
     * Without an item function, or when it returns the static items only
     * (a layout without columns), membership cannot be told.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function describeColumn(array $record, int $pageUid): array
    {
        if (!$this->tca->hasColumn(self::CONTENT_TABLE, self::COLUMN_FIELD) || !is_numeric($record[self::COLUMN_FIELD] ?? null)) {
            return [];
        }
        $colPos = (int)$record[self::COLUMN_FIELD];
        $items = $this->selectItems->getItems(self::CONTENT_TABLE, self::COLUMN_FIELD, $record, $pageUid);
        $staticItems = ProcessedSelectItems::normalize($GLOBALS['TCA'][self::CONTENT_TABLE]['columns'][self::COLUMN_FIELD]['config']['items'] ?? []);
        if ($items === null || $items === $staticItems) {
            return ['column' => ['colPos' => $colPos]];
        }

        $columns = [];
        foreach ($items as $item) {
            // Dividers and other non-column items
            if (preg_match('/^-?\d+$/D', $item['value'])) {
                $columns[] = ['colPos' => (int)$item['value'], 'label' => $this->tca->translate($item['label'])];
            }
        }
        foreach ($columns as $column) {
            if ($column['colPos'] === $colPos) {
                return ['column' => array_filter(['colPos' => $colPos, 'label' => $column['label']], static fn(int|string $value): bool => $value !== '') + ['inBackendLayout' => true]];
            }
        }
        return [
            'column' => ['colPos' => $colPos, 'inBackendLayout' => false],
            'layoutColumns' => array_map(
                static fn(array $column): array => array_filter($column, static fn(int|string $value): bool => $value !== ''),
                array_slice($columns, 0, self::MAX_LAYOUT_COLUMNS),
            ),
        ];
    }

    /**
     * The backend layout as stored: "Backend Layout (this page only)" of the
     * page, otherwise "Backend Layout (subpages of this page)" of the nearest
     * parent page that sets one, otherwise the default layout of TYPO3 (the
     * rule TYPO3 documents for both fields). Needs permission for both
     * fields; nothing is said when a parent page the reporter cannot access
     * would have to be read.
     *
     * @param array<string, mixed> $page Default language page
     * @return array<string, mixed>
     */
    private function describeBackendLayout(array $page, BackendUserAuthentication $backendUser): array
    {
        if (!$this->mayRead('pages', self::LAYOUT_FIELD, $backendUser) || !$this->mayRead('pages', self::SUBPAGES_LAYOUT_FIELD, $backendUser)) {
            return [];
        }
        $value = (string)($page[self::LAYOUT_FIELD] ?? '');
        $source = ['source' => 'page'];
        if ($value === '' || $value === '0') {
            $value = '';
            $parentUid = (int)($page['pid'] ?? 0);
            for ($depth = 0; $parentUid > 0 && $value === ''; $depth++) {
                $parent = $depth < self::MAX_PARENT_DEPTH ? $this->recordAccess->findPage($parentUid, $backendUser) : null;
                if ($parent === null) {
                    return [];
                }
                $parentValue = (string)($parent[self::SUBPAGES_LAYOUT_FIELD] ?? '');
                if ($parentValue !== '' && $parentValue !== '0') {
                    $value = $parentValue;
                    $source = ['source' => 'parentPage', 'sourcePage' => ['uid' => (int)($parent['uid'] ?? 0), 'title' => (string)($parent['title'] ?? '')]];
                }
                $parentUid = (int)($parent['pid'] ?? 0);
            }
        }
        if ($value === '') {
            return ['identifier' => 'default', 'source' => 'default'];
        }
        $title = $this->getLayoutTitle($value, $page);
        return ['identifier' => $value === self::NO_LAYOUT ? 'none' : $value] + ($title !== '' ? ['title' => $title] : []) + $source;
    }

    /**
     * The title TYPO3 offers the layout with in the page properties. Layouts
     * that no longer exist or are excluded for the reporter have none.
     *
     * @param array<string, mixed> $page
     */
    private function getLayoutTitle(string $identifier, array $page): string
    {
        foreach ($this->selectItems->getItems('pages', self::LAYOUT_FIELD, $page, (int)($page['uid'] ?? 0)) ?? [] as $item) {
            if ($item['value'] === $identifier) {
                return trim($this->tca->translate($item['label']));
            }
        }
        return '';
    }

    /**
     * "Show content from page" of the page. TYPO3 shows the value to everyone
     * who opens the page in the Page module; the page it refers to is only
     * named when the reporter may access it.
     *
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function describeContentFromPage(array $page, BackendUserAuthentication $backendUser): array
    {
        $targetUid = $this->tca->hasColumn('pages', self::CONTENT_FROM_PAGE_FIELD) ? (int)($page[self::CONTENT_FROM_PAGE_FIELD] ?? 0) : 0;
        if ($targetUid <= 0) {
            return [];
        }
        $target = $this->recordAccess->findPage($targetUid, $backendUser);
        if ($target !== null) {
            return ['uid' => $targetUid, 'title' => (string)($target['title'] ?? '')];
        }
        return ['uid' => $targetUid] + ($this->recordAccess->pageExists($targetUid) ? ['notAccessible' => true] : ['missing' => true]);
    }

    /**
     * Pages that show the content of the page: listed when accessible, otherwise counted.
     *
     * @return array<string, mixed>
     */
    private function describePagesShowingTheContent(int $pageUid, BackendUserAuthentication $backendUser): array
    {
        $listed = [];
        $notListed = 0;
        $notAccessible = 0;
        foreach ($this->recordAccess->findPagesShowingContentOf($pageUid, $backendUser, self::MAX_CHECKED_PAGES) as $row) {
            $page = $this->recordAccess->findPage((int)($row['uid'] ?? 0), $backendUser);
            if ($page === null) {
                $notAccessible++;
            } elseif (count($listed) >= self::MAX_LISTED_PAGES) {
                $notListed++;
            } else {
                $listed[] = ['uid' => (int)($page['uid'] ?? 0), 'title' => (string)($page['title'] ?? '')];
            }
        }
        if ($listed === [] && $notAccessible === 0) {
            return [];
        }
        return array_filter(['pages' => $listed, 'notListed' => $notListed, 'notAccessible' => $notAccessible]);
    }

    /**
     * Backend layouts belong to the default language page (l10n_mode exclude).
     *
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function getDefaultLanguagePage(array $page, BackendUserAuthentication $backendUser): array
    {
        $languageField = $this->tca->getLanguageField('pages');
        $parentField = $this->tca->getTranslationSourceField('pages');
        $defaultPageUid = $languageField !== '' && $parentField !== '' && (int)($page[$languageField] ?? 0) > 0 ? (int)($page[$parentField] ?? 0) : 0;
        if ($defaultPageUid <= 0) {
            return $page;
        }
        return $this->recordAccess->findPage($defaultPageUid, $backendUser) ?? [];
    }

    private function mayRead(string $table, string $field, BackendUserAuthentication $backendUser): bool
    {
        return $this->tca->hasColumn($table, $field)
            && (!$this->tca->isExcludeField($table, $field) || $backendUser->isAdmin() || $backendUser->check('non_exclude_fields', $table . ':' . $field));
    }
}
