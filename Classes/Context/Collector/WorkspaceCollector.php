<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use TYPO3\CMS\Core\Context\Context;

/**
 * @internal
 */
#[AsTaggedItem(priority: 40)]
final readonly class WorkspaceCollector implements ContextCollectorInterface
{
    public function __construct(
        private Context $context,
    ) {}

    public function getSectionKey(): string
    {
        return 'workspace';
    }

    public function collect(CollectionScope $scope): array
    {
        $workspaceId = (int)$this->context->getPropertyFromAspect('workspace', 'id', 0);
        if ($workspaceId === 0) {
            return ['id' => 0, 'title' => 'Live'];
        }
        $record = $scope->backendUser->workspaceRec;
        $title = is_array($record) && (int)($record['uid'] ?? 0) === $workspaceId ? trim((string)($record['title'] ?? '')) : '';
        return ['id' => $workspaceId, 'title' => $title !== '' ? $title : 'Workspace ' . $workspaceId];
    }
}
