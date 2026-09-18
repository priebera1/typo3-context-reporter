<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Priebera\ContextReporter\Backend\BackendLinkBuilder;
use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Priebera\ContextReporter\Context\Tca\TcaInspector;
use Priebera\ContextReporter\Domain\SubjectType;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Metadata of the record a report is about. Field values are never copied;
 * only the record identity, its label, type, language and visibility.
 *
 * @internal
 */
#[AsTaggedItem(priority: 80)]
final readonly class RecordCollector implements ContextCollectorInterface
{
    private const MAX_LABEL_LENGTH = 200;

    public function __construct(
        private TcaInspector $tca,
        private BackendLinkBuilder $links,
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
        $label = $this->createLabel($table, $record);
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
        $backendUrl = $this->links->editRecord($table, $subject->uid);
        if ($backendUrl !== '') {
            $data['backendUrl'] = $backendUrl;
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function createLabel(string $table, array $record): string
    {
        // TYPO3 falls back to other fields when the label field is empty (label_alt),
        // e.g. to the body text of a content element. Free text is content, not a title.
        foreach ($this->tca->getContentFields($table) as $field) {
            if (array_key_exists($field, $record)) {
                $record[$field] = '';
            }
        }
        try {
            $label = (string)BackendUtility::getRecordTitle($table, $record, false, false);
        } catch (\Throwable) {
            return '';
        }
        $label = trim((string)preg_replace('/\s+/', ' ', strip_tags($label)));
        return mb_strlen($label) > self::MAX_LABEL_LENGTH ? mb_substr($label, 0, self::MAX_LABEL_LENGTH) . '…' : $label;
    }
}
