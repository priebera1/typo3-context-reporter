<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Priebera\ContextReporter\Context\Subject\RecordAccess;
use Priebera\ContextReporter\Context\Tca\TcaInspector;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * State of the record editing form (FormEngine) the reporter was in: which
 * records were open, whether the form was restricted to certain fields and,
 * for new records, the structural defaults (type, language, column).
 *
 * @internal
 */
#[AsTaggedItem(priority: 70)]
final readonly class FormEngineCollector implements ContextCollectorInterface
{
    public function __construct(
        private RecordAccess $recordAccess,
        private TcaInspector $tca,
    ) {}

    public function getSectionKey(): string
    {
        return 'formEngine';
    }

    public function collect(CollectionScope $scope): array
    {
        $location = $scope->request->location;
        if ($scope->route === null || !$scope->route->isRecordEditing() || $location->editedRecords === []) {
            return [];
        }

        $data = ['mode' => $location->editedRecords[0]['command']];
        $tables = [];
        $records = [];
        $newRecord = null;
        foreach ($location->editedRecords as $entry) {
            if ($entry['command'] === 'edit') {
                $record = $this->recordAccess->findRecord($entry['table'], $entry['uid'], $scope->backendUser);
                // The UID of the record, also when the form was opened with a version UID
                $accessible = $record !== null ? ['table' => $entry['table'], 'uid' => (int)$record['uid']] : null;
                if ($accessible !== null && !in_array($accessible, $records, true)) {
                    $records[] = $accessible;
                    $tables[$entry['table']] = true;
                }
            } elseif ($newRecord === null && $this->isAccessibleNewRecordTable($entry['table'], $scope)) {
                $newRecord = ['table' => $entry['table']];
                if ($scope->subject->isNewRecord && $scope->subject->table === $entry['table']) {
                    $newRecord['pid'] = $scope->subject->pageUid;
                }
                $defaults = $this->filterDefaults($entry['table'], $location->defaultValues[$entry['table']] ?? []);
                if ($defaults !== []) {
                    $newRecord['defaults'] = $defaults;
                }
                $tables[$entry['table']] = true;
            }
        }
        if ($records !== []) {
            $data['records'] = $records;
        }
        if ($newRecord !== null) {
            $data['newRecord'] = $newRecord;
        }

        $columnsOnly = [];
        foreach ($location->columnsOnly as $table => $fields) {
            if (!isset($tables[$table])) {
                continue;
            }
            $existing = array_values(array_filter($fields, fn(string $field): bool => $this->tca->hasColumn($table, $field)));
            if ($existing !== []) {
                $columnsOnly[$table] = $existing;
            }
        }
        if ($columnsOnly !== []) {
            $data['columnsOnly'] = $columnsOnly;
        }
        return $data;
    }

    private function isAccessibleNewRecordTable(string $table, CollectionScope $scope): bool
    {
        return $this->recordAccess->allowsNewRecord($table, $scope->backendUser);
    }

    /**
     * Only structural defaults are reported: record type, language and content column.
     *
     * @param array<string, string> $defaults
     * @return array<string, string>
     */
    private function filterDefaults(string $table, array $defaults): array
    {
        $allowed = array_filter([
            $this->tca->getTypeField($table),
            $this->tca->getLanguageField($table),
            $table === 'tt_content' ? 'colPos' : '',
        ]);
        $result = [];
        foreach ($allowed as $field) {
            if (isset($defaults[$field])) {
                $result[$field] = $defaults[$field];
            }
        }
        return $result;
    }
}
