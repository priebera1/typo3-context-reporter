<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The file storage of a reported file or folder. The storage configuration
 * (base path, credentials of remote drivers) is never collected.
 *
 * @internal
 */
#[AsTaggedItem(priority: 76)]
final readonly class StorageCollector implements ContextCollectorInterface
{
    public function getSectionKey(): string
    {
        return 'storage';
    }

    public function collect(CollectionScope $scope): array
    {
        $storage = $scope->subject->file?->getStorage() ?? $scope->subject->folder?->getStorage();
        if ($storage === null) {
            return [];
        }
        $data = [
            'uid' => (int)$storage->getUid(),
            'name' => $storage->getName(),
            'driver' => $storage->getDriverType(),
            'public' => $storage->isPublic(),
        ];
        if (!$storage->isOnline()) {
            $data['online'] = false;
        }
        return $data;
    }
}
