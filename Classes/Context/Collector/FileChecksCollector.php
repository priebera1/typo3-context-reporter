<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Priebera\ContextReporter\Context\FileCheck\FileCheckEvaluator;
use Priebera\ContextReporter\Context\Subject\FileAccess;
use Priebera\ContextReporter\Context\Subject\RecordAccess;
use Priebera\ContextReporter\Context\Tca\TcaInspector;
use Priebera\ContextReporter\Domain\SubjectType;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * File problems TYPO3 knows about: for a reported file whether its storage
 * has it, whether it is marked as missing, empty or in an offline storage;
 * for a reported page or record the same index facts for the files of the
 * file fields its type shows, hidden file references and references to
 * files that no longer exist. File metadata is checked like its file, a
 * reported file reference like the references of a record.
 *
 * Referenced files are checked with the file index only, never by asking
 * their storage. Files the reporter may not access are only counted
 * ("notChecked"): neither their name nor their storage is collected.
 * Fields need the same permission as in the editing form.
 *
 * @internal
 */
#[AsTaggedItem(priority: 34)]
final readonly class FileChecksCollector implements ContextCollectorInterface
{
    private const REFERENCE_TABLE = 'sys_file_reference';
    private const METADATA_TABLE = 'sys_file_metadata';
    private const MAX_REFERENCES = 100;
    private const MAX_LISTED_PROBLEMS = 10;

    public function __construct(
        private TcaInspector $tca,
        private RecordAccess $recordAccess,
        private FileAccess $fileAccess,
        private FileCheckEvaluator $evaluator,
    ) {}

    public function getSectionKey(): string
    {
        return 'fileChecks';
    }

    public function collect(CollectionScope $scope): array
    {
        $subject = $scope->subject;
        $backendUser = $scope->backendUser;
        if ($subject->type === SubjectType::File && $subject->file !== null) {
            return ['file' => $this->evaluator->checkReportedFile($subject->file)];
        }
        $isRecord = ($subject->type === SubjectType::Record || $subject->type === SubjectType::Page)
            && !$subject->isNewRecord && $subject->uid > 0 && $subject->record !== [];
        if (!$isRecord) {
            return [];
        }

        $data = [];
        if ($subject->table === self::METADATA_TABLE) {
            // File metadata stands for its file, which the reporter may access (RecordAccess::findRecord())
            $file = $this->fileAccess->findFile($this->recordAccess->getMetadataFileUid($subject->record), $backendUser);
            if ($file !== null) {
                $data['file'] = ['uid' => (int)$file->getUid(), 'name' => $file->getName()] + $this->evaluator->checkReportedFile($file);
            }
        }
        $references = $subject->table === self::REFERENCE_TABLE
            // A reported file reference is checked like the references of a record
            ? $this->summarizeReferences((string)($subject->record['tablenames'] ?? ''), [$subject->record], $backendUser)
            : $this->checkReferences($subject->table, $subject->uid, $subject->record, $backendUser);
        if ($references !== null) {
            $data['references'] = $references;
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>|null Null when no file field can be checked
     */
    private function checkReferences(string $table, int $uid, array $record, BackendUserAuthentication $backendUser): ?array
    {
        if (!$this->recordAccess->isAllowedTable(self::REFERENCE_TABLE, $backendUser)) {
            return null;
        }
        $fields = array_values(array_filter(
            $this->tca->getShownFileFields($table, $record),
            fn(string $field): bool => $this->mayRead($table, $field, $backendUser),
        ));
        if ($fields === []) {
            return null;
        }

        return $this->summarizeReferences($table, $this->recordAccess->findFileReferences($table, $uid, $fields, $backendUser), $backendUser);
    }

    /**
     * @param string $table The table the references belong to, for the field labels
     * @param list<array<string, mixed>> $references Accessible file references
     * @return array<string, mixed>
     */
    private function summarizeReferences(string $table, array $references, BackendUserAuthentication $backendUser): array
    {
        $hiddenField = $this->tca->getDisabledField(self::REFERENCE_TABLE);
        $checked = 0;
        $notChecked = 0;
        $problems = [];
        $problemsNotListed = 0;
        foreach (array_slice($references, 0, self::MAX_REFERENCES) as $reference) {
            $fileUid = (int)($reference['uid_local'] ?? 0);
            $file = null;
            if (!$this->fileAccess->isIndexed($fileUid)) {
                $referenceProblems = [FileCheckEvaluator::BROKEN_REFERENCE];
            } else {
                $file = $this->fileAccess->findIndexedFile($fileUid, $backendUser);
                if ($file === null) {
                    // Outside the reporter's file storages or mounts: nothing about the file is collected
                    $notChecked++;
                    continue;
                }
                $referenceProblems = $this->evaluator->checkIndexedFile($file);
            }
            $checked++;
            if ($hiddenField !== '' && (bool)($reference[$hiddenField] ?? false)) {
                array_unshift($referenceProblems, FileCheckEvaluator::HIDDEN);
            }
            if ($referenceProblems === []) {
                continue;
            }
            if (count($problems) >= self::MAX_LISTED_PROBLEMS) {
                $problemsNotListed++;
                continue;
            }
            $field = (string)($reference['fieldname'] ?? '');
            $problem = [
                'field' => $field,
                'fieldLabel' => $this->tca->getColumnLabel($table, $field),
                'reference' => (int)($reference['uid'] ?? 0),
            ];
            if ($file !== null) {
                $problem['file'] = ['uid' => (int)$file->getUid(), 'name' => $file->getName()];
            }
            $problems[] = $problem + ['problems' => $referenceProblems];
        }

        $data = ['checked' => $checked, 'notChecked' => $notChecked, 'problems' => $problems];
        if ($problemsNotListed > 0) {
            $data['problemsNotListed'] = $problemsNotListed;
        }
        $overLimit = count($references) - self::MAX_REFERENCES;
        if ($overLimit > 0) {
            $data['overLimit'] = $overLimit;
        }
        return $data;
    }

    private function mayRead(string $table, string $field, BackendUserAuthentication $backendUser): bool
    {
        return !$this->tca->isExcludeField($table, $field)
            || $backendUser->isAdmin()
            || $backendUser->check('non_exclude_fields', $table . ':' . $field);
    }
}
