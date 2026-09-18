<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Priebera\ContextReporter\Backend\BackendLinkBuilder;
use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use TYPO3\CMS\Core\Resource\ResourceStorage;

/**
 * The folder a report is about, or the folder of the reported file: storage,
 * path, name and a file list link. Folder contents are not listed.
 *
 * @internal
 */
#[AsTaggedItem(priority: 77)]
final readonly class FolderCollector implements ContextCollectorInterface
{
    public function __construct(
        private BackendLinkBuilder $links,
    ) {}

    public function getSectionKey(): string
    {
        return 'folder';
    }

    public function collect(CollectionScope $scope): array
    {
        $subject = $scope->subject;
        if ($subject->folder !== null) {
            return $this->describe($subject->folder->getStorage(), $subject->folder->getIdentifier(), $subject->folder->getName());
        }
        if ($subject->file !== null) {
            $identifier = FileCollector::getFolderIdentifier($subject->file);
            return $this->describe($subject->file->getStorage(), $identifier, basename(rtrim($identifier, '/')));
        }
        return [];
    }

    /**
     * @return array<string, int|string>
     */
    private function describe(ResourceStorage $storage, string $identifier, string $name): array
    {
        return array_filter([
            'storageUid' => (int)$storage->getUid(),
            'identifier' => $identifier,
            // The root folder of a storage has no name of its own
            'name' => $name !== '' ? $name : $storage->getName(),
            'backendUrl' => $this->links->fileList($storage->getUid() . ':' . $identifier),
        ], static fn(int|string $value): bool => $value !== '');
    }
}
