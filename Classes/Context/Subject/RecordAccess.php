<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Subject;

use Priebera\ContextReporter\Context\Tca\TcaInspector;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

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

    public function __construct(
        private TcaInspector $tca,
        private FileAccess $fileAccess,
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
