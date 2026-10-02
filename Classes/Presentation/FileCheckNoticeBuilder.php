<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Presentation;

use Priebera\ContextReporter\Context\FileCheck\FileCheckEvaluator;
use Priebera\ContextReporter\Context\Tca\TcaInspector;
use Priebera\ContextReporter\Domain\ContextDocument;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Short notices about file problems of a report ("context.fileChecks") in
 * the viewer's backend language, for the report dialog and the report
 * detail. Nothing is shown when the checks found nothing and every
 * referenced file could be checked.
 *
 * @internal
 */
final readonly class FileCheckNoticeBuilder
{
    use NoticeTrait;

    private const SEPARATOR = ' · ';

    private const REPORTED_FILE_LABELS = [
        FileCheckEvaluator::NOT_IN_STORAGE => 'fileChecks.notInStorage',
        FileCheckEvaluator::STORAGE_OFFLINE => 'fileChecks.storageOffline',
        FileCheckEvaluator::EMPTY => 'fileChecks.empty',
    ];

    private const REFERENCE_LABELS = [
        FileCheckEvaluator::HIDDEN => 'fileChecks.hidden',
        FileCheckEvaluator::MISSING => 'fileChecks.markedMissing',
        FileCheckEvaluator::STORAGE_OFFLINE => 'fileChecks.storageOffline',
        FileCheckEvaluator::EMPTY => 'fileChecks.empty',
        FileCheckEvaluator::TYPE_NOT_ALLOWED => 'fileChecks.typeNotAllowed',
    ];

    public function __construct(
        private TcaInspector $tca,
    ) {}

    /**
     * @return list<string>
     */
    public function build(ContextDocument $document, LanguageService $languageService): array
    {
        $checks = $document->getContextSection('fileChecks');
        if ($checks === []) {
            return [];
        }
        $table = $this->string($document->getSubject(), 'table');
        return array_merge(
            $this->describeReportedFile($this->array($checks, 'file'), $languageService),
            $this->describeReferences($this->array($checks, 'references'), $table, $languageService),
        );
    }

    /**
     * @param array<array-key, mixed> $file
     * @return list<string>
     */
    private function describeReportedFile(array $file, LanguageService $languageService): array
    {
        $problems = $this->problems($file);
        $notices = [];
        // "Not found in its storage" already says that the file is missing
        if (in_array(FileCheckEvaluator::MISSING, $problems, true) && !in_array(FileCheckEvaluator::NOT_IN_STORAGE, $problems, true)) {
            $notices[] = $this->label(
                $this->string($file, 'storageCheck') === FileCheckEvaluator::STORAGE_CHECK_FOUND ? 'fileChecks.markedMissingButFound' : 'fileChecks.markedMissing',
                $languageService,
            );
        }
        foreach (self::REPORTED_FILE_LABELS as $problem => $labelKey) {
            if (in_array($problem, $problems, true)) {
                $notices[] = $this->label($labelKey, $languageService);
            }
        }
        return $notices;
    }

    /**
     * @param array<array-key, mixed> $references
     * @return list<string>
     */
    private function describeReferences(array $references, string $table, LanguageService $languageService): array
    {
        $notices = [];
        foreach ($this->list($references, 'problems') as $reference) {
            $field = $this->string($reference, 'field');
            $fieldLabel = ($this->tca->hasColumn($table, $field) ? $this->tca->getColumnLabel($table, $field, $languageService) : '')
                ?: $this->string($reference, 'fieldLabel') ?: $field;
            $problems = $this->problems($reference);
            $facts = [];
            foreach (self::REFERENCE_LABELS as $problem => $labelKey) {
                if (in_array($problem, $problems, true)) {
                    $facts[] = $this->label($labelKey, $languageService);
                }
            }
            if (in_array(FileCheckEvaluator::BROKEN_REFERENCE, $problems, true)) {
                array_unshift($facts, $this->label('fileChecks.broken', $languageService));
                $notices[] = sprintf($this->label('fileChecks.brokenReference', $languageService), $fieldLabel, implode(self::SEPARATOR, $facts));
                continue;
            }
            if ($facts !== []) {
                $file = $this->array($reference, 'file');
                $notices[] = sprintf($this->label('fileChecks.reference', $languageService), $fieldLabel, $this->string($file, 'name'), implode(self::SEPARATOR, $facts));
            }
        }
        $counts = [
            'fileChecks.problemsNotListed' => (int)($references['problemsNotListed'] ?? 0),
            'fileChecks.notChecked' => (int)($references['notChecked'] ?? 0),
            'fileChecks.overLimit' => (int)($references['overLimit'] ?? 0),
        ];
        foreach ($counts as $labelKey => $count) {
            if ($count > 0) {
                $notices[] = sprintf($this->label($labelKey, $languageService), $count);
            }
        }
        return $notices;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<string>
     */
    private function problems(array $data): array
    {
        return array_values(array_filter($this->array($data, 'problems'), is_string(...)));
    }
}
