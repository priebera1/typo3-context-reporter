<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Tca;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The fields the editing form shows for a record type, read from the TCA
 * "types" configuration the way TYPO3 does it (TcaColumnsProcessShowitem):
 * comma separated "field;label", "--palette--;label;name" and "--div--;label"
 * entries, the fields of the palettes they refer to and, on TYPO3 v13, the
 * subtype additions and exclusions (TcaTypesShowitem). TYPO3 v14 removes the
 * subtype options while migrating the TCA, so they never apply there.
 *
 * @internal
 */
final readonly class ShowitemParser
{
    /**
     * @param array<array-key, mixed> $type Configuration of the record type ("types.<type>")
     * @param array<array-key, mixed> $palettes The "palettes" of the table
     * @param array<array-key, mixed> $row The record, for the subtype value
     * @return list<string>
     */
    public function getFields(array $type, array $palettes, array $row): array
    {
        $fields = $this->parse(is_string($type['showitem'] ?? null) ? $type['showitem'] : '', $palettes);
        $subtypeField = $type['subtype_value_field'] ?? '';
        if (is_string($subtypeField) && $subtypeField !== '' && is_scalar($row[$subtypeField] ?? null)) {
            $subtype = (string)$row[$subtypeField];
            $added = $type['subtypes_addlist'][$subtype] ?? '';
            if (is_string($added)) {
                $fields = array_merge($fields, $this->parse($added, $palettes));
            }
            $excluded = $type['subtypes_excludelist'][$subtype] ?? '';
            if (is_string($excluded)) {
                $fields = array_diff($fields, GeneralUtility::trimExplode(',', $excluded, true));
            }
        }
        return array_values(array_unique($fields));
    }

    /**
     * @param array<array-key, mixed> $palettes
     * @return list<string>
     */
    private function parse(string $showitem, array $palettes): array
    {
        $fields = [];
        foreach (GeneralUtility::trimExplode(',', $showitem, true) as $item) {
            $parts = GeneralUtility::trimExplode(';', $item);
            if ($parts[0] === '--palette--') {
                $palette = $palettes[$parts[2] ?? '']['showitem'] ?? '';
                foreach (GeneralUtility::trimExplode(',', is_string($palette) ? $palette : '', true) as $paletteItem) {
                    $fields[] = GeneralUtility::trimExplode(';', $paletteItem)[0];
                }
            } else {
                $fields[] = $parts[0];
            }
        }
        return array_values(array_filter(
            $fields,
            static fn(string $field): bool => $field !== '' && !str_starts_with($field, '--'),
        ));
    }
}
