<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Location;

use Priebera\ContextReporter\Context\ReportTarget;

/**
 * Parses the location data sent by the report dialog. The browser sends the
 * URL of the current backend document; only the path and a small set of
 * well-known TYPO3 parameters are kept. Tokens, return URLs and everything
 * else are dropped.
 *
 * @internal
 */
final class BackendLocationFactory
{
    public const MAX_EDITED_RECORDS = 20;

    private const TABLE_NAME = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/D';
    private const FIELD_NAME = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/D';
    private const IDENTIFIER = '/^[A-Za-z0-9_]{1,100}$/D';
    private const PATH = '~^/[A-Za-z0-9/_.\-]{0,254}$~D';
    private const UID_LIST = '/^-?\d{1,10}(,-?\d{1,10})*$/D';
    private const MAX_COLUMNS = 50;
    private const MAX_DEFAULT_VALUES = 10;
    private const MAX_DEFAULT_VALUE_LENGTH = 64;

    /**
     * @param array<array-key, mixed> $raw
     */
    public function fromArray(array $raw): BackendLocation
    {
        $url = $raw['url'] ?? '';
        $path = '';
        $query = [];
        if (is_string($url) && $url !== '' && strlen($url) <= 8192) {
            [$path, $query] = $this->parseUrl($url);
        }

        $editedRecords = $this->parseEditConfiguration($query['edit'] ?? null);
        $editedTables = array_values(array_unique(array_column($editedRecords, 'table')));

        return new BackendLocation(
            path: $path,
            pageId: $this->positiveInt($query['id'] ?? null),
            editedRecords: $editedRecords,
            columnsOnly: $this->parseColumnsOnly($query['columnsOnly'] ?? null, $editedTables),
            defaultValues: $this->parseDefaultValues($query['defVals'] ?? null, $editedTables),
            listTable: $this->matches($query['table'] ?? null, self::TABLE_NAME),
            moduleIdentifier: $this->matches($raw['module'] ?? null, self::IDENTIFIER),
            activeModuleIdentifier: $this->matches($raw['activeModule'] ?? null, self::IDENTIFIER),
            pageTreeSelection: $this->positiveInt($raw['pageTreeSelection'] ?? null),
            folderIdentifier: $this->folderIdentifier($query['id'] ?? null),
        );
    }

    /**
     * @return array{0: string, 1: array<array-key, mixed>}
     */
    private function parseUrl(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return ['', []];
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== '' && $scheme !== 'http' && $scheme !== 'https') {
            return ['', []];
        }
        $path = $parts['path'] ?? '';
        if (!preg_match(self::PATH, $path)) {
            return ['', []];
        }
        $query = [];
        parse_str($parts['query'] ?? '', $query);
        return [$path, $query];
    }

    /**
     * @return list<array{table: string, uid: int, command: 'edit'|'new'}>
     */
    private function parseEditConfiguration(mixed $edit): array
    {
        if (!is_array($edit)) {
            return [];
        }
        $records = [];
        foreach ($edit as $table => $commands) {
            if (!is_string($table) || !preg_match(self::TABLE_NAME, $table) || !is_array($commands)) {
                continue;
            }
            foreach ($commands as $uidList => $command) {
                if (($command !== 'edit' && $command !== 'new') || !preg_match(self::UID_LIST, (string)$uidList)) {
                    continue;
                }
                foreach (explode(',', (string)$uidList) as $uid) {
                    $uid = (int)$uid;
                    if ($command === 'edit' && $uid <= 0) {
                        continue;
                    }
                    $records[] = ['table' => $table, 'uid' => $uid, 'command' => $command];
                    if (count($records) >= self::MAX_EDITED_RECORDS) {
                        return $records;
                    }
                }
            }
        }
        return $records;
    }

    /**
     * @param list<string> $editedTables
     * @return array<string, list<string>>
     */
    private function parseColumnsOnly(mixed $columnsOnly, array $editedTables): array
    {
        $result = [];
        if (is_string($columnsOnly)) {
            // Deprecated TYPO3 format: one comma separated list for all edited tables
            $columnsOnly = array_fill_keys($editedTables, $columnsOnly);
        }
        if (!is_array($columnsOnly)) {
            return [];
        }
        foreach ($columnsOnly as $table => $fields) {
            if (!is_string($table) || !in_array($table, $editedTables, true)) {
                continue;
            }
            if (is_string($fields)) {
                $fields = explode(',', $fields);
            }
            if (!is_array($fields)) {
                continue;
            }
            $valid = [];
            foreach ($fields as $field) {
                $field = is_string($field) ? trim($field) : '';
                if (preg_match(self::FIELD_NAME, $field) && !in_array($field, $valid, true)) {
                    $valid[] = $field;
                }
            }
            if ($valid !== []) {
                $result[$table] = array_slice($valid, 0, self::MAX_COLUMNS);
            }
        }
        return $result;
    }

    /**
     * @param list<string> $editedTables
     * @return array<string, array<string, string>>
     */
    private function parseDefaultValues(mixed $defaultValues, array $editedTables): array
    {
        if (!is_array($defaultValues)) {
            return [];
        }
        $result = [];
        foreach ($defaultValues as $table => $fields) {
            if (!is_string($table) || !in_array($table, $editedTables, true) || !is_array($fields)) {
                continue;
            }
            foreach ($fields as $field => $value) {
                if (!is_string($field)
                    || !preg_match(self::FIELD_NAME, $field)
                    || !is_scalar($value)
                    || strlen((string)$value) > self::MAX_DEFAULT_VALUE_LENGTH
                    || preg_match('/[\x00-\x1F\x7F]/', (string)$value)
                ) {
                    continue;
                }
                $result[$table][$field] = (string)$value;
                if (count($result[$table]) >= self::MAX_DEFAULT_VALUES) {
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * The file list uses "id" for the combined identifier of the current folder.
     */
    private function folderIdentifier(mixed $value): string
    {
        return is_string($value) && ReportTarget::isValidFolderIdentifier($value) ? $value : '';
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^\d{1,10}$/D', $value) && (int)$value > 0) {
            return (int)$value;
        }
        return null;
    }

    private function matches(mixed $value, string $pattern): string
    {
        return is_string($value) && preg_match($pattern, $value) ? $value : '';
    }
}
