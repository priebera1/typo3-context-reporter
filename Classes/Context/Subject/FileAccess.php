<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Subject;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\Security\FileNameValidator;

/**
 * Read access checks for files and folders (FAL), built on the public
 * resource API: the file storages of the backend user and the read
 * permissions the storage evaluates (user file permissions, file mounts,
 * driver permissions). Nothing is indexed or created on the way.
 *
 * Access fails closed: a resource whose storage, driver or index entry is
 * unusable is not accessible, whatever the lookup throws (also errors of
 * third-party drivers), so callers such as the context menu never fail.
 *
 * @internal
 */
final readonly class FileAccess
{
    public function __construct(
        private ResourceFactory $resourceFactory,
        private ConnectionPool $connectionPool,
        private FileNameValidator $fileNameValidator,
    ) {}

    public function findFile(int $uid, BackendUserAuthentication $backendUser): ?File
    {
        if ($uid <= 0) {
            return null;
        }
        try {
            $file = $this->resourceFactory->getFileObject($uid);
            if ($file->isDeleted()
                || !$this->isAccessibleStorage($file->getStorage(), $backendUser)
                || !$file->checkActionPermission('read')
            ) {
                return null;
            }
        } catch (\Throwable) {
            // Unknown file, unknown storage, no permission or an unusable storage
            return null;
        }
        return $file;
    }

    /**
     * Read access to an indexed file without asking the storage driver, for
     * files that are only referenced: the file storages of the user, their
     * "read file" permission, the denied file extensions and the file mounts.
     * findFile() additionally lets the storage look up the file and its
     * permissions (ResourceStorage::checkFileActionPermission()).
     */
    public function findIndexedFile(int $uid, BackendUserAuthentication $backendUser): ?File
    {
        if ($uid <= 0) {
            return null;
        }
        try {
            $file = $this->resourceFactory->getFileObject($uid);
            $storage = $file->getStorage();
            if ($file->isDeleted()
                || !$this->isAccessibleStorage($storage, $backendUser)
                || !$storage->checkUserActionPermission('read', 'File')
                || !$this->fileNameValidator->isValid($file->getName())
                || !$storage->isWithinFileMountBoundaries($file)
            ) {
                return null;
            }
        } catch (\Throwable) {
            // Unknown storage, no permission or an unusable storage
            return null;
        }
        return $file;
    }

    /**
     * Whether the file index knows the file, regardless of access. Only used
     * to tell a reference to a file that no longer exists from a reference to
     * a file the user may not access; nothing about the file is returned.
     */
    public function isIndexed(int $uid): bool
    {
        if ($uid <= 0) {
            return false;
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->getRestrictions()->removeAll();
        return (int)$queryBuilder
            ->count('uid')
            ->from('sys_file')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne() > 0;
    }

    /**
     * @param string $combinedIdentifier "<storage UID>:<folder path>"
     */
    public function findFolder(string $combinedIdentifier, BackendUserAuthentication $backendUser): ?Folder
    {
        if (!preg_match('/^[1-9]\d{0,9}:\//D', $combinedIdentifier)) {
            return null;
        }
        try {
            $folder = $this->resourceFactory->getFolderObjectFromCombinedIdentifier($combinedIdentifier);
            if (!$folder instanceof Folder
                || !$this->isAccessibleStorage($folder->getStorage(), $backendUser)
                || !$folder->checkActionPermission('read')
            ) {
                return null;
            }
        } catch (\Throwable) {
            // Unknown folder, unknown storage, no permission or an unusable storage
            return null;
        }
        return $folder;
    }

    /**
     * The file or folder with the given combined identifier, e.g. from the
     * context menu of the file list.
     */
    public function findResource(string $combinedIdentifier, BackendUserAuthentication $backendUser): File|Folder|null
    {
        if (!preg_match('/^[1-9]\d{0,9}:\//D', $combinedIdentifier)) {
            return null;
        }
        try {
            $resource = $this->resourceFactory->getObjectFromCombinedIdentifier($combinedIdentifier);
        } catch (\Throwable) {
            // e.g. a file deleted after the file list was loaded, or an unusable storage
            return null;
        }
        return match (true) {
            $resource instanceof File => $this->findFile((int)$resource->getUid(), $backendUser),
            $resource instanceof Folder => $this->findFolder($resource->getCombinedIdentifier(), $backendUser),
            default => null,
        };
    }

    private function isAccessibleStorage(ResourceStorage $storage, BackendUserAuthentication $backendUser): bool
    {
        $uid = (int)$storage->getUid();
        if ($uid <= 0 || $storage->isFallbackStorage()) {
            return false;
        }
        return $backendUser->isAdmin() || array_key_exists($uid, $backendUser->getFileStorages());
    }
}
