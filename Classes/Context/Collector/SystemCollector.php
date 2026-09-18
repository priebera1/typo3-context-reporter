<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Priebera\ContextReporter\Configuration\ExtensionInfo;
use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * Versions and runtime facts. No paths, host names, credentials or settings.
 *
 * @internal
 */
#[AsTaggedItem(priority: 20)]
final readonly class SystemCollector implements ContextCollectorInterface
{
    public function __construct(
        private Typo3Version $typo3Version,
        private ConnectionPool $connectionPool,
        private ExtensionInfo $extensionInfo,
    ) {}

    public function getSectionKey(): string
    {
        return 'system';
    }

    public function collect(CollectionScope $scope): array
    {
        $system = [
            'typo3Version' => $this->typo3Version->getVersion(),
            'phpVersion' => PHP_VERSION,
            'applicationContext' => (string)Environment::getContext(),
            'composerMode' => Environment::isComposerMode(),
        ];
        $database = $this->describeDatabase();
        if ($database !== '') {
            $system['databasePlatform'] = $database;
        }
        $system['operatingSystem'] = PHP_OS_FAMILY;
        if ($this->extensionInfo->getVersion() !== '') {
            $system['extensionVersion'] = $this->extensionInfo->getVersion();
        }
        return $system;
    }

    private function describeDatabase(): string
    {
        try {
            $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
            $platform = $connection->getDatabasePlatform();
            $name = match (true) {
                $platform instanceof MariaDBPlatform => 'MariaDB',
                $platform instanceof AbstractMySQLPlatform => 'MySQL',
                $platform instanceof PostgreSQLPlatform => 'PostgreSQL',
                $platform instanceof SQLitePlatform => 'SQLite',
                default => (new \ReflectionClass($platform))->getShortName(),
            };
            $version = preg_match('/\d+(\.\d+){0,2}/', $connection->getServerVersion(), $matches) ? $matches[0] : '';
            return trim($name . ' ' . $version);
        } catch (\Throwable) {
            return '';
        }
    }
}
