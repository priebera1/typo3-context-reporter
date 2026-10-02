<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Priebera\ContextReporter\Context\Subject\RecordAccess;
use Priebera\ContextReporter\Context\Tca\TcaInspector;
use Priebera\ContextReporter\Domain\SubjectType;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Permission facts of the reporter for the reported object, each read with
 * the public permission API of TYPO3: table permission, page permissions,
 * edit locks, language, record type ("authMode"), page type, fields that
 * are not available (exclude fields, fields of the default language in a
 * translation, fields disabled in TSconfig) and, for files and folders, the
 * file permissions of the reporter's groups, read-only file mounts and the
 * storage.
 *
 * These are inputs, not TYPO3's decision: whether a record can be edited or
 * saved also depends on internal checks, workspaces, hooks and event
 * listeners of extensions, which are not evaluated. Only the reporter's own
 * permissions are described; page owners, groups and other users are not.
 * Administrators have all permissions, so nothing is collected for them.
 *
 * @internal
 */
#[AsTaggedItem(priority: 32)]
final readonly class PermissionsCollector implements ContextCollectorInterface
{
    private const PAGE_PERMISSIONS = [
        'show' => Permission::PAGE_SHOW,
        'editPage' => Permission::PAGE_EDIT,
        'deletePage' => Permission::PAGE_DELETE,
        'newPages' => Permission::PAGE_NEW,
        'editContent' => Permission::CONTENT_EDIT,
    ];
    private const FILE_ACTIONS = ['read', 'write', 'rename', 'replace', 'move', 'copy', 'delete'];
    private const FOLDER_ACTIONS = ['read', 'write', 'add', 'rename', 'move', 'copy', 'delete'];
    private const MAX_LISTED_FIELDS = 20;

    public function __construct(
        private TcaInspector $tca,
        private RecordAccess $recordAccess,
    ) {}

    public function getSectionKey(): string
    {
        return 'permissions';
    }

    public function collect(CollectionScope $scope): array
    {
        $subject = $scope->subject;
        $backendUser = $scope->backendUser;
        if ($backendUser->isAdmin()) {
            return [];
        }
        return match (true) {
            $subject->type === SubjectType::File && $subject->file !== null => $this->describeFile($subject->file),
            $subject->type === SubjectType::Folder && $subject->folder !== null => $this->describeFolder($subject->folder),
            $subject->type === SubjectType::Page && $subject->record !== [] => $this->describeRecord('pages', $subject->record, $this->getDefaultLanguagePage($subject->record, $backendUser), $backendUser),
            $subject->type === SubjectType::Record && $subject->isNewRecord => $this->describeNewRecord($subject->table, $subject->page, $backendUser),
            $subject->type === SubjectType::Record && $subject->record !== [] => $this->describeRecord($subject->table, $subject->record, $subject->page, $backendUser),
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function describeNewRecord(string $table, array $page, BackendUserAuthentication $backendUser): array
    {
        $data = ['table' => $this->describeTable($table, $backendUser)];
        if ($page !== []) {
            $data['page'] = $this->describePage($page, $backendUser);
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $record The record, or the page for a page
     * @param array<string, mixed> $page The default language page the record is on, or the page itself
     * @return array<string, mixed>
     */
    private function describeRecord(string $table, array $record, array $page, BackendUserAuthentication $backendUser): array
    {
        $data = ['table' => $this->describeTable($table, $backendUser)];
        if ($page !== []) {
            $data['page'] = $this->describePage($page, $backendUser);
        }

        $locks = [];
        $pageLockField = $this->tca->getEditLockField('pages');
        if ($page !== [] && $pageLockField !== '' && (bool)($page[$pageLockField] ?? false)) {
            $locks['page'] = true;
        }
        $recordLockField = $table !== 'pages' ? $this->tca->getEditLockField($table) : '';
        if ($recordLockField !== '' && (bool)($record[$recordLockField] ?? false)) {
            $locks['record'] = true;
        }
        if ($locks !== []) {
            $data['editLock'] = $locks;
        }

        $languageField = $this->tca->getLanguageField($table);
        if ($languageField !== '' && is_numeric($record[$languageField] ?? null)) {
            $languageId = (int)$record[$languageField];
            $data['language'] = ['id' => $languageId, 'allowed' => $backendUser->checkLanguageAccess($languageId)];
        }

        $recordTypes = [];
        foreach ($this->tca->getAuthModeFields($table) as $field) {
            $value = $record[$field] ?? null;
            if (is_scalar($value) && !is_bool($value) && (string)$value !== '') {
                $recordTypes[] = ['field' => $field, 'value' => (string)$value, 'allowed' => $backendUser->checkAuthMode($table, $field, (string)$value)];
            }
        }
        if ($recordTypes !== []) {
            $data['recordType'] = $recordTypes;
        }

        if ($table === 'pages') {
            $doktype = (int)($record['doktype'] ?? 0);
            $data['pageType'] = ['doktype' => $doktype, 'allowed' => $backendUser->check('pagetypes_select', (string)$doktype)];
        }

        $effectivePid = $table === 'pages' ? (int)($page['uid'] ?? 0) : (int)($record['pid'] ?? 0);
        $fields = $this->describeFields($table, $record, $effectivePid, $backendUser);
        if ($fields !== []) {
            $data['fields'] = $fields;
        }
        return $data;
    }

    /**
     * @return array<string, bool>
     */
    private function describeTable(string $table, BackendUserAuthentication $backendUser): array
    {
        return ['modify' => $backendUser->check('tables_modify', $table)]
            + array_filter(['adminOnly' => $this->tca->isAdminOnly($table), 'readOnly' => $this->tca->isReadOnly($table)]);
    }

    /**
     * The page permissions of the reporter, as TYPO3 combines them from the
     * owner, group and everybody settings of the page.
     *
     * @param array<string, mixed> $page
     * @return array<string, int|bool>
     */
    private function describePage(array $page, BackendUserAuthentication $backendUser): array
    {
        $permissions = (int)$backendUser->calcPerms($page);
        $data = ['uid' => (int)($page['uid'] ?? 0)];
        foreach (self::PAGE_PERMISSIONS as $key => $bit) {
            $data[$key] = ($permissions & $bit) === $bit;
        }
        return $data;
    }

    /**
     * Fields of the record type that are not available to the reporter, in
     * the order of the editing form: exclude fields without permission,
     * fields a translation takes from the default language and fields
     * disabled in the TSconfig of the page (TCEFORM, also per record type).
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function describeFields(string $table, array $record, int $effectivePid, BackendUserAuthentication $backendUser): array
    {
        $languageField = $this->tca->getLanguageField($table);
        $sourceField = $this->tca->getTranslationSourceField($table);
        $isTranslation = $languageField !== '' && $sourceField !== '' && (int)($record[$languageField] ?? 0) > 0 && (int)($record[$sourceField] ?? 0) > 0;
        $formTsConfig = BackendUtility::getPagesTSconfig($effectivePid)['TCEFORM.'][$table . '.'] ?? [];
        try {
            $type = (string)BackendUtility::getTCAtypeValue($table, $record);
        } catch (\Throwable) {
            $type = '';
        }

        $lists = ['notAllowed' => [], 'defaultLanguageOnly' => [], 'disabled' => []];
        foreach ($this->tca->getShownFields($table, $record) as $field) {
            $entry = ['field' => $field, 'label' => $this->tca->getColumnLabel($table, $field) ?: $field];
            if ($this->tca->isExcludeField($table, $field) && !$backendUser->check('non_exclude_fields', $table . ':' . $field)) {
                $lists['notAllowed'][] = $entry;
            }
            if ($isTranslation && $this->tca->getTranslationMode($table, $field) === 'exclude') {
                $lists['defaultLanguageOnly'][] = $entry;
            }
            if ($this->isDisabledInTsConfig(is_array($formTsConfig) ? ($formTsConfig[$field . '.'] ?? null) : null, $type)) {
                $lists['disabled'][] = $entry;
            }
        }

        $fields = [];
        foreach ($lists as $key => $entries) {
            if ($entries === []) {
                continue;
            }
            $fields[$key] = array_slice($entries, 0, self::MAX_LISTED_FIELDS);
            if (count($entries) > self::MAX_LISTED_FIELDS) {
                $fields[$key . 'NotListed'] = count($entries) - self::MAX_LISTED_FIELDS;
            }
        }
        return $fields;
    }

    /**
     * "TCEFORM.<table>.<field>.disabled", overridden per record type by
     * "TCEFORM.<table>.<field>.types.<type>.disabled", as the editing form merges it.
     */
    private function isDisabledInTsConfig(mixed $fieldTsConfig, string $type): bool
    {
        if (!is_array($fieldTsConfig)) {
            return false;
        }
        $typeTsConfig = $fieldTsConfig['types.'][$type . '.'] ?? null;
        if (is_array($typeTsConfig) && array_key_exists('disabled', $typeTsConfig)) {
            return (bool)$typeTsConfig['disabled'];
        }
        return (bool)($fieldTsConfig['disabled'] ?? false);
    }

    /**
     * The file permissions of the reporter's groups, without asking the storage driver.
     *
     * @return array<string, mixed>
     */
    private function describeFile(File $file): array
    {
        $storage = $file->getStorage();
        $actions = [];
        foreach (self::FILE_ACTIONS as $action) {
            $actions[$action] = $storage->checkUserActionPermission($action, 'File');
        }
        return [
            'fileActions' => $actions,
            'writableFileMount' => $storage->isWithinFileMountBoundaries($file, true),
            'storageWritable' => $storage->isWritable(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeFolder(Folder $folder): array
    {
        $storage = $folder->getStorage();
        $actions = [];
        foreach (self::FOLDER_ACTIONS as $action) {
            $actions[$action] = $storage->checkUserActionPermission($action, 'Folder');
        }
        return [
            'folderActions' => $actions,
            // Uploads into the folder
            'fileActions' => ['add' => $storage->checkUserActionPermission('add', 'File')],
            'writableFileMount' => $storage->isWithinFileMountBoundaries($folder, true),
            'storageWritable' => $storage->isWritable(),
        ];
    }

    /**
     * Page permissions and locks belong to the default language page.
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
}
