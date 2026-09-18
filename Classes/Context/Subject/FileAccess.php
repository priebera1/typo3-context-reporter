<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Subject;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;

/**
 * Read access checks for files and folders (FAL), built on the public
 * resource API: the file storages of the backend user and the read
 * permissions the storage evaluates (user file permissions, file mounts,
 * driver permissions). Nothing is indexed or created on the way.
 *
 * @internal
 */
final readonly class FileAccess
{
    public function __construct(
        private ResourceFactory $resourceFactory,
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
        } catch (\Exception) {
            // Unknown file, unknown storage or no permission
            return null;
        }
        return $file;
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
        } catch (\Exception) {
            // Unknown folder, unknown storage or no permission
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
        } catch (\Exception) {
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
