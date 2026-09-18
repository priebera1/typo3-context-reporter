<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Priebera\ContextReporter\Backend\BackendLinkBuilder;
use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use TYPO3\CMS\Core\Resource\File;

/**
 * The file (FAL) a report is about: index facts only. Neither the file
 * content nor metadata values (title, alternative text, ...) are collected.
 *
 * @internal
 */
#[AsTaggedItem(priority: 78)]
final readonly class FileCollector implements ContextCollectorInterface
{
    public function __construct(
        private BackendLinkBuilder $links,
    ) {}

    public function getSectionKey(): string
    {
        return 'file';
    }

    public function collect(CollectionScope $scope): array
    {
        $file = $scope->subject->file;
        if ($file === null) {
            return [];
        }
        $storage = $file->getStorage();
        $data = [
            'uid' => (int)$file->getUid(),
            'storageUid' => (int)$storage->getUid(),
            'identifier' => $file->getIdentifier(),
            'name' => $file->getName(),
            'extension' => strtolower($file->getExtension()),
            'mimeType' => $file->getMimeType(),
            'size' => (int)$file->getSize(),
        ];
        if ($file->isMissing()) {
            $data['missing'] = true;
        }
        $metadataUid = $this->findMetadataUid($file);
        if ($metadataUid > 0) {
            $data['metadataUid'] = $metadataUid;
        }
        $folderIdentifier = self::getFolderIdentifier($file);
        return array_filter($data + [
            'backendUrl' => $this->links->fileList($storage->getUid() . ':' . $folderIdentifier),
            'editMetadataUrl' => $metadataUid > 0 ? $this->links->editRecord('sys_file_metadata', $metadataUid) : '',
        ], static fn(mixed $value): bool => $value !== '');
    }

    /**
     * Identifier of the folder a file is stored in, without a permission check
     * of the folder itself (the file was already checked).
     */
    public static function getFolderIdentifier(File $file): string
    {
        return (string)$file->getStorage()->getFolderIdentifierFromFileIdentifier($file->getIdentifier());
    }

    private function findMetadataUid(File $file): int
    {
        try {
            $uid = $file->getMetaData()->get()['uid'] ?? 0;
        } catch (\Exception) {
            return 0;
        }
        return is_numeric($uid) ? (int)$uid : 0;
    }
}
