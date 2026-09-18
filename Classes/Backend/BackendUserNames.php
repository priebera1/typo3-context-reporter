<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Backend;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Display names of backend users for the report history, including users
 * that have been deleted since.
 *
 * @internal
 */
final readonly class BackendUserNames
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<int> $uids
     * @return array<int, array{uid: int, username: string, realName: string, deleted: bool, label: string}>
     */
    public function resolve(array $uids): array
    {
        $uids = array_values(array_unique(array_filter($uids, static fn(int $uid): bool => $uid > 0)));
        if ($uids === []) {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeByType(DeletedRestriction::class);
        $rows = $queryBuilder
            ->select('uid', 'username', 'realName', 'deleted')
            ->from('be_users')
            ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery()
            ->fetchAllAssociative();

        $names = [];
        foreach ($rows as $row) {
            $uid = (int)$row['uid'];
            $realName = (string)$row['realName'];
            $username = (string)$row['username'];
            $names[$uid] = [
                'uid' => $uid,
                'username' => $username,
                'realName' => $realName,
                'deleted' => (bool)$row['deleted'],
                'label' => $realName !== '' ? $realName . ' (' . $username . ')' : $username,
            ];
        }
        foreach ($uids as $uid) {
            $names[$uid] ??= ['uid' => $uid, 'username' => '', 'realName' => '', 'deleted' => true, 'label' => '#' . $uid];
        }
        return $names;
    }
}
