<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Subject;

use Priebera\ContextReporter\Context\Tca\TcaInspector;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Read access checks for pages and records, built on the public permission
 * API of the backend user: table permissions, web mounts and page permissions.
 *
 * File metadata and root level file references identify a file; they are
 * only accessible together with the file (FileAccess), because their tables
 * ignore the web mount and root level restrictions.
 *
 * @internal
 */
final readonly class RecordAccess
{
    /**
     * Tables that are never accepted as report subjects: file objects are
     * identified differently, the others are logs and technical storage.
     */
    public const DENIED_TABLES = [
        'sys_file',
        'sys_file_processedfile',
        'sys_log',
        'sys_history',
        'sys_registry',
        'sys_refindex',
        'sys_http_report',
        'be_sessions',
        'fe_sessions',
    ];

    /**
     * Tables whose records cannot be created as report subjects, because a
     * new record is not bound to an accessible object yet.
     */
    private const NO_NEW_RECORD_TABLES = ['sys_file_metadata'];

    private const FILE_REFERENCE_TABLE = 'sys_file_reference';

    public function __construct(
        private TcaInspector $tca,
        private FileAccess $fileAccess,
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array<string, mixed>|null The workspace overlaid page row, with the live UID
     */
    public function findPage(int $uid, BackendUserAuthentication $backendUser): ?array
    {
        if ($uid <= 0) {
            return null;
        }
        $page = $this->findInCurrentWorkspace('pages', $uid, $backendUser);
        if ($page === null
            || !$backendUser->isInWebMount($page)
            || !$backendUser->doesUserHaveAccess($page, Permission::PAGE_SHOW)
        ) {
            return null;
        }
        return $page;
    }

    /**
     * Whether the page is translated into the language in the user's current
     * workspace. Hidden translations count, as they do in the Page module.
     */
    public function hasPageTranslation(int $pageUid, int $languageId, BackendUserAuthentication $backendUser): bool
    {
        return $languageId > 0 && isset($this->findTranslations('pages', $pageUid, $backendUser)[$languageId]);
    }

    /**
     * The translations of a record in the user's current workspace, keyed by
     * language ID, as workspace overlaid rows. Hidden translations count, as
     * they do in the Page module; translations deleted in the workspace do
     * not. Translations on pages the user cannot access are left out.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findTranslations(string $table, int $uid, BackendUserAuthentication $backendUser): array
    {
        $languageField = $this->tca->getLanguageField($table);
        $parentField = $this->tca->getTranslationSourceField($table);
        if ($uid <= 0 || $languageField === '' || $parentField === '' || ($table !== 'pages' && !$this->isAllowedTable($table, $backendUser))) {
            return [];
        }
        $workspace = (int)$backendUser->workspace;
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($workspace));
        $rows = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq($parentField, $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt($languageField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $translations = [];
        $allowedLocations = [];
        foreach ($rows as $row) {
            BackendUtility::workspaceOL($table, $row, $workspace);
            // A translation deleted in the workspace is gone for the user
            if (!is_array($row) || VersionState::tryFrom((int)($row['t3ver_state'] ?? 0)) === VersionState::DELETE_PLACEHOLDER) {
                continue;
            }
            $languageId = (int)($row[$languageField] ?? 0);
            if ($languageId <= 0 || isset($translations[$languageId])) {
                continue;
            }
            if ($table !== 'pages') {
                $pid = (int)($row['pid'] ?? 0);
                $allowedLocations[$pid] ??= $this->isAllowedLocation($table, $pid, $backendUser);
                if (!$allowedLocations[$pid]) {
                    continue;
                }
            }
            $translations[$languageId] = $row;
        }
        return $translations;
    }

    /**
     * The file references of an accessible record in the given fields, as
     * workspace overlaid rows of the user's current workspace, in the order
     * of the fields and their sorting. References deleted in the workspace
     * are left out, hidden references are included. Requires permission to
     * list file references; the record itself must already be checked.
     *
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    public function findFileReferences(string $table, int $uid, array $fields, BackendUserAuthentication $backendUser): array
    {
        if ($uid <= 0 || $fields === [] || !$this->isAllowedTable(self::FILE_REFERENCE_TABLE, $backendUser)) {
            return [];
        }
        $workspace = (int)$backendUser->workspace;
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::FILE_REFERENCE_TABLE);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($workspace));
        $rows = $queryBuilder
            ->select('*')
            ->from(self::FILE_REFERENCE_TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->in('fieldname', $queryBuilder->createNamedParameter($fields, Connection::PARAM_STR_ARRAY)),
            )
            ->orderBy('sorting_foreign')
            ->addOrderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $references = [];
        foreach ($rows as $row) {
            BackendUtility::workspaceOL(self::FILE_REFERENCE_TABLE, $row, $workspace);
            // A reference deleted in the workspace is gone for the user
            if (!is_array($row) || VersionState::tryFrom((int)($row['t3ver_state'] ?? 0)) === VersionState::DELETE_PLACEHOLDER) {
                continue;
            }
            $references[] = $row;
        }
        $fieldOrder = array_flip($fields);
        // usort() is stable: the sorting within a field is kept
        usort($references, static fn(array $a, array $b): int => ($fieldOrder[$a['fieldname'] ?? ''] ?? 0) <=> ($fieldOrder[$b['fieldname'] ?? ''] ?? 0));
        return $references;
    }

    /**
     * @return array<string, mixed>|null The workspace overlaid record row, with the live UID
     */
    public function findRecord(string $table, int $uid, BackendUserAuthentication $backendUser): ?array
    {
        if ($table === 'pages') {
            return $this->findPage($uid, $backendUser);
        }
        if ($uid <= 0 || !$this->isAllowedTable($table, $backendUser)) {
            return null;
        }
        $record = $this->findInCurrentWorkspace($table, $uid, $backendUser);
        if ($record === null
            || !$this->isAllowedLocation($table, (int)($record['pid'] ?? 0), $backendUser)
            || !$this->allowsFilesOfRecord($table, $record, $backendUser)
        ) {
            return null;
        }
        return $record;
    }

    /**
     * Whether a new record of the table may be the subject of a report.
     */
    public function allowsNewRecord(string $table, BackendUserAuthentication $backendUser): bool
    {
        return $table === 'pages'
            || ($this->isAllowedTable($table, $backendUser) && !in_array($table, self::NO_NEW_RECORD_TABLES, true));
    }

    public function isAllowedTable(string $table, BackendUserAuthentication $backendUser): bool
    {
        return !in_array($table, self::DENIED_TABLES, true)
            && $this->tca->hasTable($table)
            && $backendUser->check('tables_select', $table);
    }

    /**
     * The record as the backend user sees it in their current workspace.
     *
     * getRecordWSOL() does not check the workspace of the given UID itself:
     * offline versions and new records are only available in their own
     * workspace, never in live or another workspace. The UID of an offline
     * version resolves to its live record with that version overlaid, so the
     * report describes the object the user works with.
     *
     * @return array<string, mixed>|null The row with the live UID
     */
    private function findInCurrentWorkspace(string $table, int $uid, BackendUserAuthentication $backendUser): ?array
    {
        $record = BackendUtility::getRecordWSOL($table, $uid);
        if (!is_array($record)) {
            return null;
        }
        if (!$this->tca->isWorkspaceAware($table)) {
            return $record;
        }
        $recordWorkspace = (int)($record['t3ver_wsid'] ?? 0);
        if ($recordWorkspace !== 0 && $recordWorkspace !== (int)$backendUser->workspace) {
            return null;
        }
        $liveUid = (int)($record['t3ver_oid'] ?? 0);
        if ($liveUid > 0 && (int)($record['uid'] ?? 0) !== $liveUid) {
            $record = BackendUtility::getRecordWSOL($table, $liveUid);
            // The overlay must be the requested version, otherwise the version is not the current one
            if (!is_array($record) || (int)($record['_ORIG_uid'] ?? 0) !== $uid) {
                return null;
            }
        }
        return $record;
    }

    /**
     * File metadata requires access to its file, including the file of the
     * default language record of a translation. File references on pages
     * follow the page permissions like their parent records; root level
     * references (e.g. of backend users) require access to the file.
     *
     * @param array<string, mixed> $record
     */
    private function allowsFilesOfRecord(string $table, array $record, BackendUserAuthentication $backendUser): bool
    {
        $fileUids = match ($table) {
            'sys_file_metadata' => $this->getMetadataFileUids($record),
            'sys_file_reference' => (int)($record['pid'] ?? 0) > 0 ? null : [(int)($record['uid_local'] ?? 0)],
            default => null,
        };
        if ($fileUids === null) {
            return true;
        }
        foreach ($fileUids as $fileUid) {
            if ($this->fileAccess->findFile($fileUid, $backendUser) === null) {
                return false;
            }
        }
        return $fileUids !== [];
    }

    /**
     * The file a file metadata record describes: its own file, or the file of
     * its default language record. Access is checked by findRecord().
     *
     * @param array<string, mixed> $metadata
     */
    public function getMetadataFileUid(array $metadata): int
    {
        return $this->getMetadataFileUids($metadata)[0] ?? 0;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return list<int>
     */
    private function getMetadataFileUids(array $metadata): array
    {
        $fileUids = [];
        if ((int)($metadata['file'] ?? 0) > 0) {
            $fileUids[] = (int)$metadata['file'];
        }
        $translationSourceField = $this->tca->getTranslationSourceField('sys_file_metadata');
        $parentUid = $translationSourceField !== '' ? (int)($metadata[$translationSourceField] ?? 0) : 0;
        if ($parentUid > 0) {
            $parent = BackendUtility::getRecord('sys_file_metadata', $parentUid, 'file');
            // A translation without its default language record is not bound to a known file
            $fileUids[] = is_array($parent) ? (int)($parent['file'] ?? 0) : 0;
        }
        return array_values(array_unique($fileUids));
    }

    /**
     * Whether records of the table on the given page (0 = root level) may be seen.
     */
    public function isAllowedLocation(string $table, int $pageUid, BackendUserAuthentication $backendUser): bool
    {
        if ($pageUid > 0) {
            return $this->findPage($pageUid, $backendUser) !== null;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        return $table !== 'pages' && $this->tca->allowsRootLevelRecordsForEditors($table);
    }
}
