<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Priebera\ContextReporter\Context\Subject\RecordAccess;
use Priebera\ContextReporter\Context\Subject\RecordLabel;
use Priebera\ContextReporter\Context\Tca\TcaInspector;
use Priebera\ContextReporter\Domain\SubjectType;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Where a reported file is used: the file references of the reporter's
 * current workspace and the records and fields they belong to.
 *
 * A usage is only named when the reporter may access its record (table and
 * page permissions, workspace) and its field; all others are counted.
 * Records of users and groups are never named. Links to the file in text
 * (soft references) are not file references and are not included. The
 * references are read from the database, not from the reference index.
 *
 * @internal
 */
#[AsTaggedItem(priority: 33)]
final readonly class FileUsageCollector implements ContextCollectorInterface
{
    /** Records that identify people are only counted */
    private const UNLISTED_TABLES = ['be_users', 'be_groups', 'fe_users', 'fe_groups'];
    private const MAX_CHECKED_REFERENCES = 100;
    private const MAX_LISTED_USAGES = 10;

    public function __construct(
        private TcaInspector $tca,
        private RecordAccess $recordAccess,
        private RecordLabel $recordLabel,
    ) {}

    public function getSectionKey(): string
    {
        return 'fileUsage';
    }

    public function collect(CollectionScope $scope): array
    {
        $file = $scope->subject->file;
        if ($scope->subject->type !== SubjectType::File || $file === null) {
            return [];
        }
        $backendUser = $scope->backendUser;
        $found = $this->recordAccess->findReferencesToFile((int)$file->getUid(), $backendUser, self::MAX_CHECKED_REFERENCES);

        $usages = [];
        $notListed = 0;
        $notAccessible = 0;
        $records = [];
        foreach ($found['references'] as $reference) {
            $table = (string)($reference['tablenames'] ?? '');
            $uid = (int)($reference['uid_foreign'] ?? 0);
            $field = (string)($reference['fieldname'] ?? '');
            $key = $table . ':' . $uid;
            if (!array_key_exists($key, $records)) {
                $records[$key] = in_array($table, self::UNLISTED_TABLES, true) ? null : $this->recordAccess->findRecord($table, $uid, $backendUser);
            }
            $record = $records[$key];
            if ($record === null || !$this->mayRead($table, $field, $backendUser)) {
                $notAccessible++;
                continue;
            }
            if (count($usages) >= self::MAX_LISTED_USAGES) {
                $notListed++;
                continue;
            }
            $usages[] = $this->describeUsage($table, $record, $field, $reference, $backendUser);
        }

        return array_filter([
            'references' => $found['total'],
            'usages' => $usages,
            'notListed' => $notListed,
            'notAccessible' => $notAccessible,
            'notChecked' => max(0, $found['total'] - count($found['references'])),
        ], static fn(int|array $value, string $key): bool => $key === 'references' || ($value !== 0 && $value !== []), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $reference
     * @return array<string, mixed>
     */
    private function describeUsage(string $table, array $record, string $field, array $reference, BackendUserAuthentication $backendUser): array
    {
        $usage = ['table' => $table, 'uid' => (int)($record['uid'] ?? 0)];
        $label = $this->recordLabel->create($table, $record);
        if ($label !== '') {
            $usage['label'] = $label;
        }
        $usage['field'] = $field;
        $fieldLabel = $this->tca->getColumnLabel($table, $field);
        if ($fieldLabel !== '') {
            $usage['fieldLabel'] = $fieldLabel;
        }
        $usage['reference'] = (int)($reference['uid'] ?? 0);
        $pageUid = (int)($record['pid'] ?? 0);
        if ($table !== 'pages' && $pageUid > 0) {
            // The record is accessible, so is its page
            $page = $this->recordAccess->findPage($pageUid, $backendUser);
            if ($page !== null) {
                $usage['page'] = ['uid' => $pageUid, 'title' => (string)($page['title'] ?? '')];
            }
        }
        $hiddenField = $this->tca->getDisabledField('sys_file_reference');
        if ($hiddenField !== '' && (bool)($reference[$hiddenField] ?? false)) {
            $usage['hidden'] = true;
        }
        return $usage;
    }

    private function mayRead(string $table, string $field, BackendUserAuthentication $backendUser): bool
    {
        return !$this->tca->isExcludeField($table, $field)
            || $backendUser->isAdmin()
            || $backendUser->check('non_exclude_fields', $table . ':' . $field);
    }
}
