<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Priebera\ContextReporter\Context\Tca\TcaInspector;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;

/**
 * The backend view: module, route and relevant backend session facts.
 *
 * @internal
 */
#[AsTaggedItem(priority: 100)]
final readonly class BackendCollector implements ContextCollectorInterface
{
    public function __construct(
        private ModuleProvider $moduleProvider,
        private TcaInspector $tca,
    ) {}

    public function getSectionKey(): string
    {
        return 'backend';
    }

    public function collect(CollectionScope $scope): array
    {
        $location = $scope->request->location;
        $backend = [];

        $module = $this->findModule(
            [$scope->route !== null ? $scope->route->moduleIdentifier : '', $location->moduleIdentifier, $location->activeModuleIdentifier],
            $scope,
        );
        if ($module !== null) {
            $backend['module'] = array_filter([
                'identifier' => $module->getIdentifier(),
                'title' => $this->tca->translate($module->getTitle()),
                'group' => $module->getParentModule() !== null ? $this->tca->translate($module->getParentModule()->getTitle()) : '',
            ], static fn(string $value): bool => $value !== '');
        }
        if ($scope->route !== null) {
            $backend['route'] = ['identifier' => $scope->route->identifier, 'path' => $scope->route->path];
        }
        $parameters = $location->getSafeQueryParameters();
        if ($parameters !== []) {
            $backend['parameters'] = $parameters;
        }

        $language = (string)($scope->backendUser->user['lang'] ?? '');
        $backend['backendLanguage'] = in_array($language, ['', 'default'], true) ? 'en' : $language;
        return $backend;
    }

    /**
     * @param list<string> $candidates
     */
    private function findModule(array $candidates, CollectionScope $scope): ?ModuleInterface
    {
        foreach ($candidates as $identifier) {
            if ($identifier === '') {
                continue;
            }
            $module = $this->moduleProvider->getModule($identifier, $scope->backendUser);
            if ($module !== null) {
                return $module;
            }
        }
        return null;
    }
}
