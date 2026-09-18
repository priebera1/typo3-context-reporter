<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Tca;

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

    public function getLanguageField(string $table): string
    {
        return $this->ctrlString($table, 'languageField');
    }

    public function getTranslationSourceField(string $table): string
    {
        return $this->ctrlString($table, 'transOrigPointerField');
    }

    public function getDisabledField(string $table): string
    {
        $field = $GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['disabled'] ?? '';
        return is_string($field) ? $field : '';
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

    private function ctrlString(string $table, string $key): string
    {
        $value = $GLOBALS['TCA'][$table]['ctrl'][$key] ?? '';
        return is_string($value) ? $value : '';
    }
}
