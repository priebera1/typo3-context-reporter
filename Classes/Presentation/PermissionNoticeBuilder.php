<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Presentation;

use Priebera\ContextReporter\Context\Tca\TcaInspector;
use Priebera\ContextReporter\Domain\ContextDocument;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Notices about the permission facts of a report ("context.permissions") in
 * the viewer's backend language. Only permissions the reporter does not have
 * and locks are mentioned, each as a fact; nothing says whether the object
 * can be edited, because TYPO3 decides that with more checks.
 *
 * @internal
 */
final readonly class PermissionNoticeBuilder
{
    use NoticeTrait;

    private const MAX_NAMED_FIELDS = 8;
    private const FIELD_LISTS = [
        'notAllowed' => 'access.fieldsNotAllowed',
        'defaultLanguageOnly' => 'access.fieldsDefaultLanguage',
        'disabled' => 'access.fieldsDisabled',
    ];

    public function __construct(
        private TcaInspector $tca,
    ) {}

    /**
     * @return list<string>
     */
    public function build(ContextDocument $document, LanguageService $languageService): array
    {
        $permissions = $document->getContextSection('permissions');
        if ($permissions === []) {
            return [];
        }
        $subject = $document->getSubject();
        $table = $this->string($subject, 'table');
        $isPage = $this->string($subject, 'type') === 'page';
        $tableTitle = $this->tca->hasTable($table)
            ? $this->tca->getTableTitle($table, $languageService)
            : ($this->string($document->getContextSection('record'), 'tableTitle') ?: $table);
        $pageTitle = $this->string($document->getContextSection('page'), 'title');

        $notices = [];
        $tablePermissions = $this->array($permissions, 'table');
        if (($tablePermissions['modify'] ?? true) === false) {
            $notices[] = sprintf($this->label('access.tableNotModifiable', $languageService), $tableTitle);
        }
        if (($tablePermissions['adminOnly'] ?? false) === true) {
            $notices[] = sprintf($this->label('access.adminOnly', $languageService), $tableTitle);
        }
        if (($tablePermissions['readOnly'] ?? false) === true) {
            $notices[] = sprintf($this->label('access.readOnly', $languageService), $tableTitle);
        }
        $page = $this->array($permissions, 'page');
        $pagePermission = $isPage ? 'editPage' : 'editContent';
        if (($page[$pagePermission] ?? true) === false) {
            $notices[] = sprintf($this->label($isPage ? 'access.noPageEdit' : 'access.noContentEdit', $languageService), $pageTitle);
        }
        $locks = $this->array($permissions, 'editLock');
        if (($locks['page'] ?? false) === true) {
            $notices[] = sprintf($this->label('access.pageLocked', $languageService), $pageTitle);
        }
        if (($locks['record'] ?? false) === true) {
            $notices[] = $this->label('access.recordLocked', $languageService);
        }
        $language = $this->array($permissions, 'language');
        if (($language['allowed'] ?? true) === false) {
            $notices[] = sprintf($this->label('access.languageNotAllowed', $languageService), $this->getLanguageName($document, $this->string($language, 'id')));
        }
        foreach ($this->list($permissions, 'recordType') as $recordType) {
            if (($recordType['allowed'] ?? true) === false) {
                $field = $this->string($recordType, 'field');
                $value = $this->string($recordType, 'value');
                $notices[] = sprintf(
                    $this->label('access.recordTypeNotAllowed', $languageService),
                    $this->tca->getColumnLabel($table, $field, $languageService) ?: $field,
                    $this->tca->getItemLabel($table, $field, $value, $languageService) ?: $value,
                );
            }
        }
        $pageType = $this->array($permissions, 'pageType');
        if (($pageType['allowed'] ?? true) === false) {
            $doktype = $this->string($pageType, 'doktype');
            $notices[] = sprintf($this->label('access.pageTypeNotAllowed', $languageService), $this->tca->getItemLabel('pages', 'doktype', $doktype, $languageService) ?: $doktype);
        }
        $fields = $this->array($permissions, 'fields');
        foreach (self::FIELD_LISTS as $key => $labelKey) {
            $names = $this->describeFields($table, $this->list($fields, $key), $this->int($fields, $key . 'NotListed'), $languageService);
            if ($names !== '') {
                $notices[] = sprintf($this->label($labelKey, $languageService), $names);
            }
        }
        return [...$notices, ...$this->describeFilePermissions($permissions, $languageService)];
    }

    /**
     * @param list<array<array-key, mixed>> $fields
     */
    private function describeFields(string $table, array $fields, int $notListed, LanguageService $languageService): string
    {
        $names = [];
        foreach ($fields as $field) {
            $name = $this->string($field, 'field');
            $names[] = ($this->tca->hasColumn($table, $name) ? $this->tca->getColumnLabel($table, $name, $languageService) : '') ?: $this->string($field, 'label') ?: $name;
        }
        $more = max(0, count($names) - self::MAX_NAMED_FIELDS) + $notListed;
        $names = array_slice($names, 0, self::MAX_NAMED_FIELDS);
        if ($more > 0) {
            $names[] = sprintf($this->label('access.moreFields', $languageService), $more);
        }
        return implode(', ', $names);
    }

    /**
     * File and folder actions as the backend group form names them.
     *
     * @param array<array-key, mixed> $permissions
     * @return list<string>
     */
    private function describeFilePermissions(array $permissions, LanguageService $languageService): array
    {
        $notices = [];
        foreach (['fileActions' => ['File', 'access.fileActionsMissing'], 'folderActions' => ['Folder', 'access.folderActionsMissing']] as $key => [$type, $labelKey]) {
            $missing = [];
            foreach ($this->array($permissions, $key) as $action => $granted) {
                if ($granted === false) {
                    $missing[] = $this->tca->getItemLabel('be_groups', 'file_permissions', $action . $type, $languageService) ?: (string)$action;
                }
            }
            if ($missing !== []) {
                $notices[] = sprintf($this->label($labelKey, $languageService), implode(', ', $missing));
            }
        }
        if (($permissions['writableFileMount'] ?? true) === false) {
            $notices[] = $this->label('access.mountReadOnly', $languageService);
        }
        if (($permissions['storageWritable'] ?? true) === false) {
            $notices[] = $this->label('access.storageNotWritable', $languageService);
        }
        return $notices;
    }

    private function getLanguageName(ContextDocument $document, string $languageId): string
    {
        $language = $document->getContextSection('language');
        return $this->string($language, 'id') === $languageId ? ($this->string($language, 'title') ?: $languageId) : $languageId;
    }
}
