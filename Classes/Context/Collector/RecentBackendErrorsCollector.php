<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Priebera\ContextReporter\Security\ReportAccessPolicy;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Log\LogDataTrait;
use TYPO3\CMS\Core\SysLog\Error as SystemLogError;

/**
 * Opt-in: the reporter's own recent error and warning entries from the
 * backend log (sys_log), e.g. DataHandler errors after a failed save.
 * Disabled by default because log messages may quote record titles.
 *
 * @internal
 */
#[AsTaggedItem(priority: 30)]
final class RecentBackendErrorsCollector implements ContextCollectorInterface
{
    use LogDataTrait;

    private const WINDOW_MINUTES = 30;
    private const MAX_ENTRIES = 10;
    private const MAX_MESSAGE_LENGTH = 300;
    private const SEVERITIES = [
        SystemLogError::USER_ERROR => 'error',
        SystemLogError::SYSTEM_ERROR => 'systemError',
        SystemLogError::WARNING => 'warning',
    ];

    public function __construct(
        private readonly ExtensionSettingsProvider $settingsProvider,
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getSectionKey(): string
    {
        return 'recentErrors';
    }

    public function collect(CollectionScope $scope): array
    {
        if (!$this->settingsProvider->get()->includeRecentBackendErrors) {
            return [];
        }
        $userId = ReportAccessPolicy::getUserId($scope->backendUser);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_log');
        $rows = $queryBuilder
            ->select('tstamp', 'error', 'details', 'log_data', 'tablename', 'recuid')
            ->from('sys_log')
            ->where(
                $queryBuilder->expr()->eq('userid', $queryBuilder->createNamedParameter($userId, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('error', $queryBuilder->createNamedParameter(array_keys(self::SEVERITIES), Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->gte('tstamp', $queryBuilder->createNamedParameter(time() - self::WINDOW_MINUTES * 60, Connection::PARAM_INT)),
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(self::MAX_ENTRIES)
            ->executeQuery()
            ->fetchAllAssociative();

        $entries = [];
        foreach ($rows as $row) {
            $message = $this->formatLogDetails((string)($row['details'] ?? ''), $row['log_data'] ?? '');
            $message = trim((string)preg_replace('/\s+/', ' ', strip_tags($message)));
            if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
                $message = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH) . '…';
            }
            $entry = [
                'time' => date(\DateTimeInterface::ATOM, (int)$row['tstamp']),
                'severity' => self::SEVERITIES[(int)$row['error']] ?? 'error',
                'message' => $message,
            ];
            if ((string)$row['tablename'] !== '') {
                $entry['table'] = (string)$row['tablename'];
            }
            if ((int)$row['recuid'] > 0) {
                $entry['recordUid'] = (int)$row['recuid'];
            }
            $entries[] = $entry;
        }
        return $entries === [] ? [] : ['windowMinutes' => self::WINDOW_MINUTES, 'entries' => $entries];
    }
}
