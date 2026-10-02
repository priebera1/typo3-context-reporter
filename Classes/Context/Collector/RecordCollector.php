<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Priebera\ContextReporter\Backend\BackendLinkBuilder;
use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Priebera\ContextReporter\Context\ServerTime;
use Priebera\ContextReporter\Context\Subject\FileAccess;
use Priebera\ContextReporter\Context\Subject\RecordLabel;
use Priebera\ContextReporter\Context\Tca\TcaInspector;
use Priebera\ContextReporter\Domain\SubjectType;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Metadata of the record a report is about. Field values are never copied;
 * only the record identity, its label, type, language, visibility and the
 * times TYPO3 keeps for it (created, last changed).
 *
 * @internal
 */
#[AsTaggedItem(priority: 80)]
final readonly class RecordCollector implements ContextCollectorInterface
{
    public function __construct(
        private TcaInspector $tca,
        private BackendLinkBuilder $links,
        private FileAccess $fileAccess,
        private RecordLabel $recordLabel,
    ) {}

    public function getSectionKey(): string
    {
        return 'record';
    }

    public function collect(CollectionScope $scope): array
    {
        $subject = $scope->subject;
        if ($subject->type !== SubjectType::Record) {
            return [];
        }
        $table = $subject->table;
        $data = [
            'table' => $table,
            'tableTitle' => $this->tca->getTableTitle($table),
        ];
        if ($subject->isNewRecord) {
            $data['isNew'] = true;
            $data['pid'] = $subject->pageUid;
            return $data;
        }

        $record = $subject->record;
        $data['uid'] = $subject->uid;
        $data['pid'] = (int)($record['pid'] ?? 0);
        $label = $this->mayUseLabel($table, $record, $scope->backendUser) ? $this->recordLabel->create($table, $record) : '';
        if ($label !== '') {
            $data['label'] = $label;
        }

        $typeField = $this->tca->getTypeField($table);
        if ($typeField !== '' && is_scalar($record[$typeField] ?? null)) {
            $value = (string)$record[$typeField];
            $data['type'] = ['field' => $typeField, 'value' => $value];
            $typeLabel = $this->tca->getItemLabel($table, $typeField, $value);
            if ($typeLabel !== '') {
                $data['type']['label'] = $typeLabel;
            }
        }
        $languageField = $this->tca->getLanguageField($table);
        if ($languageField !== '' && is_numeric($record[$languageField] ?? null)) {
            $data['languageId'] = (int)$record[$languageField];
        }
        $translationSourceField = $this->tca->getTranslationSourceField($table);
        if ($translationSourceField !== '' && (int)($record[$translationSourceField] ?? 0) > 0) {
            $data['translationSourceUid'] = (int)$record[$translationSourceField];
        }
        if ($table === 'tt_content' && is_numeric($record['colPos'] ?? null)) {
            $data['colPos'] = (int)$record['colPos'];
        }
        $disabledField = $this->tca->getDisabledField($table);
        if ($disabledField !== '' && isset($record[$disabledField])) {
            $data['hidden'] = (bool)$record[$disabledField];
        }
        $versionUid = (int)($record['_ORIG_uid'] ?? 0);
        if ($versionUid > 0 && $versionUid !== $subject->uid) {
            $data['workspaceVersionUid'] = $versionUid;
        }
        // The row as the reporter sees it: in a workspace, the times of the version
        $data += ServerTime::describeRecord($record, $this->tca->getCreationTimeField($table), $this->tca->getChangeTimeField($table));
        $backendUrl = $this->links->editRecord($table, $subject->uid);
        if ($backendUrl !== '') {
            $data['backendUrl'] = $backendUrl;
        }
        return $data;
    }

    /**
     * File references are labelled with the name of their file (label field
     * "uid_local"); references to files the reporter may not access, e.g. on
     * a page they can edit, stay without label.
     *
     * @param array<string, mixed> $record
     */
    private function mayUseLabel(string $table, array $record, BackendUserAuthentication $backendUser): bool
    {
        return $table !== 'sys_file_reference'
            || $this->fileAccess->findIndexedFile((int)($record['uid_local'] ?? 0), $backendUser) !== null;
    }
}
