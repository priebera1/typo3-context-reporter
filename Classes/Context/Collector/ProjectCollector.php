<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use TYPO3\CMS\Core\Routing\BackendEntryPointResolver;

/**
 * Which TYPO3 installation the report comes from.
 *
 * @internal
 */
#[AsTaggedItem(priority: 200)]
final readonly class ProjectCollector implements ContextCollectorInterface
{
    public function __construct(
        private ExtensionSettingsProvider $settingsProvider,
        private BackendEntryPointResolver $backendEntryPointResolver,
    ) {}

    public function getSectionKey(): string
    {
        return 'project';
    }

    public function collect(CollectionScope $scope): array
    {
        $settings = $this->settingsProvider->get();
        $project = ['name' => $settings->projectName];
        if ($settings->projectIdentifier !== '') {
            $project['identifier'] = $settings->projectIdentifier;
        }
        $project['environment'] = $settings->environment;
        try {
            $project['backendUrl'] = (string)$this->backendEntryPointResolver->getUriFromRequest($scope->httpRequest);
        } catch (\Throwable) {
            // The backend URL is a convenience only
        }
        return $project;
    }
}
