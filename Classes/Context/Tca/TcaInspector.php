<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Tca;

use Priebera\ContextReporter\Context\Visibility\EnableFields;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Read-only access to the few TCA facts the collectors need. Labels are
 * English unless a language service is given (reports are English, their
 * presentation in the backend follows the viewer's language). This is the
 * single place that reads $GLOBALS['TCA'], so it can move to the Schema API
 * (public from TYPO3 v14) without touching the collectors.
 *
 * @internal
 */
final class TcaInspector
{
    private ?LanguageService $languageService = null;

    public function __construct(
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly ShowitemParser $showitemParser,
    ) {}

    public function hasTable(string $table): bool
    {
        return $table !== '' && is_array($GLOBALS['TCA'][$table]['ctrl'] ?? null);
    }

    public function hasColumn(string $table, string $field): bool
    {
        return $field !== '' && is_array($GLOBALS['TCA'][$table]['columns'][$field] ?? null);
    }

    public function getTableTitle(string $table, ?LanguageService $languageService = null): string
    {
        $title = $this->translate($this->ctrlString($table, 'title'), $languageService);
        return $title !== '' ? $title : $table;
    }

    /**
     * The record type field, unless the type is defined through a relation.
     */
    public function getTypeField(string $table): string
    {
        $field = $this->ctrlString($table, 'type');
        return !str_contains($field, ':') && $this->hasColumn($table, $field) ? $field : '';
    }

    /**
     * Fields that hold free text content (multi-line text, JSON, FlexForms).
     *
     * @return list<string>
     */
    public function getContentFields(string $table): array
    {
        $fields = [];
        foreach ($GLOBALS['TCA'][$table]['columns'] ?? [] as $field => $column) {
            if (is_string($field) && in_array($column['config']['type'] ?? '', ['text', 'json', 'flex'], true)) {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    /**
     * The fields the editing form shows for the type of the record, as far
     * as the TCA defines them as columns. Display conditions are not evaluated.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public function getShownFields(string $table, array $row): array
    {
        if (!$this->hasTable($table)) {
            return [];
        }
        try {
            $type = (string)BackendUtility::getTCAtypeValue($table, $row);
        } catch (\Throwable) {
            return [];
        }
        $typeConfiguration = $GLOBALS['TCA'][$table]['types'][$type] ?? null;
        $palettes = $GLOBALS['TCA'][$table]['palettes'] ?? [];
        if (!is_array($typeConfiguration)) {
            return [];
        }
        $fields = $this->showitemParser->getFields($typeConfiguration, is_array($palettes) ? $palettes : [], $row);
        return array_values(array_filter($fields, fn(string $field): bool => $this->hasColumn($table, $field)));
    }

    /**
     * File fields (TCA type "file") the editing form shows for the type of
     * the record. Fields of other types keep their references when the type
     * changes; those are not shown and not returned.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public function getShownFileFields(string $table, array $row): array
    {
        return array_values(array_filter(
            $this->getShownFields($table, $row),
            static fn(string $field): bool => ($GLOBALS['TCA'][$table]['columns'][$field]['config']['type'] ?? '') === 'file',
        ));
    }

    /**
     * The configuration of a field for the type of the record: the field
     * "config" with the "columnsOverrides" of the record type, as DataHandler
     * and the editing form use it.
     *
     * @param array<string, mixed> $row
     * @return array<array-key, mixed>
     */
    public function getFieldConfiguration(string $table, string $field, array $row): array
    {
        $config = $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? null;
        if (!is_array($config)) {
            return [];
        }
        try {
            $type = (string)BackendUtility::getTCAtypeValue($table, $row);
        } catch (\Throwable) {
            return $config;
        }
        $overrides = $GLOBALS['TCA'][$table]['types'][$type]['columnsOverrides'][$field]['config'] ?? null;
        return is_array($overrides) ? array_replace_recursive($config, $overrides) : $config;
    }

    public function getColumnLabel(string $table, string $field, ?LanguageService $languageService = null): string
    {
        $label = $GLOBALS['TCA'][$table]['columns'][$field]['label'] ?? '';
        return $this->translate(is_string($label) ? $label : '', $languageService);
    }

    public function getLanguageField(string $table): string
    {
        return $this->ctrlString($table, 'languageField');
    }

    public function getTranslationSourceField(string $table): string
    {
        return $this->ctrlString($table, 'transOrigPointerField');
    }

    /**
     * The field TYPO3 stores the creation time in ("ctrl.crdate").
     */
    public function getCreationTimeField(string $table): string
    {
        return $this->ctrlString($table, 'crdate');
    }

    /**
     * The field TYPO3 stores the time of the last change in ("ctrl.tstamp").
     */
    public function getChangeTimeField(string $table): string
    {
        return $this->ctrlString($table, 'tstamp');
    }

    public function getDisabledField(string $table): string
    {
        return $this->getEnableColumn($table, 'disabled');
    }

    /**
     * The visibility fields of "ctrl.enablecolumns" that exist as columns.
     */
    public function getEnableFields(string $table): EnableFields
    {
        return new EnableFields(
            hidden: $this->getEnableColumn($table, 'disabled'),
            startTime: $this->getEnableColumn($table, 'starttime'),
            endTime: $this->getEnableColumn($table, 'endtime'),
            frontendGroups: $this->getEnableColumn($table, 'fe_group'),
        );
    }

    /**
     * Whether backend users need an explicit permission for the field
     * ("Allowed excludefields" of their groups).
     */
    public function isExcludeField(string $table, string $field): bool
    {
        return (bool)($GLOBALS['TCA'][$table]['columns'][$field]['exclude'] ?? false);
    }

    /**
     * Whether only administrators may modify records of the table ("ctrl.adminOnly").
     */
    public function isAdminOnly(string $table): bool
    {
        return (bool)($GLOBALS['TCA'][$table]['ctrl']['adminOnly'] ?? false);
    }

    /**
     * The field that locks a record for editing by non-administrators ("ctrl.editlock").
     */
    public function getEditLockField(string $table): string
    {
        $field = $this->ctrlString($table, 'editlock');
        return $this->hasColumn($table, $field) ? $field : '';
    }

    /**
     * How a field behaves in translations ("l10n_mode"), e.g. "exclude":
     * translations use the value of the default language.
     */
    public function getTranslationMode(string $table, string $field): string
    {
        $mode = $GLOBALS['TCA'][$table]['columns'][$field]['l10n_mode'] ?? '';
        return is_string($mode) ? $mode : '';
    }

    /**
     * Select fields whose values need an explicit permission of the user
     * ("authMode"), e.g. the content type of content elements.
     *
     * @return list<string>
     */
    public function getAuthModeFields(string $table): array
    {
        $fields = [];
        foreach ($GLOBALS['TCA'][$table]['columns'] ?? [] as $field => $column) {
            if (is_string($field) && is_array($column) && ($column['config']['authMode'] ?? '') !== '') {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    public function isReadOnly(string $table): bool
    {
        return (bool)($GLOBALS['TCA'][$table]['ctrl']['readOnly'] ?? false);
    }

    /**
     * Whether the table keeps workspace versions.
     *
     * Reads the same TCA flag as BackendUtility::isTableWorkspaceEnabled()
     * (TYPO3 v13) and the Schema API capability "Workspace" (TYPO3 v14), which
     * is deprecated respectively internal in the other branch.
     */
    public function isWorkspaceAware(string $table): bool
    {
        return (bool)($GLOBALS['TCA'][$table]['ctrl']['versioningWS'] ?? false);
    }

    /**
     * Whether non-admin users may work with records stored on the root level (pid 0).
     */
    public function allowsRootLevelRecordsForEditors(string $table): bool
    {
        return (bool)($GLOBALS['TCA'][$table]['ctrl']['security']['ignoreRootLevelRestriction'] ?? false);
    }

    /**
     * Label of a select item, e.g. the content type "Text & Media".
     */
    public function getItemLabel(string $table, string $field, string $value, ?LanguageService $languageService = null): string
    {
        $items = $GLOBALS['TCA'][$table]['columns'][$field]['config']['items'] ?? [];
        if (!is_array($items)) {
            return '';
        }
        foreach ($items as $item) {
            if (is_array($item) && array_key_exists('value', $item) && (string)$item['value'] === $value) {
                return $this->translate(is_string($item['label'] ?? null) ? $item['label'] : '', $languageService);
            }
        }
        return '';
    }

    /**
     * Resolves label references, in English by default. Plain strings are returned unchanged.
     */
    public function translate(string $label, ?LanguageService $languageService = null): string
    {
        if ($label === '') {
            return '';
        }
        if ($languageService === null) {
            $this->languageService ??= $this->languageServiceFactory->create('en');
            $languageService = $this->languageService;
        }
        return $languageService->sL($label);
    }

    private function getEnableColumn(string $table, string $key): string
    {
        $field = $GLOBALS['TCA'][$table]['ctrl']['enablecolumns'][$key] ?? '';
        return is_string($field) && $this->hasColumn($table, $field) ? $field : '';
    }

    private function ctrlString(string $table, string $key): string
    {
        $value = $GLOBALS['TCA'][$table]['ctrl'][$key] ?? '';
        return is_string($value) ? $value : '';
    }
}
