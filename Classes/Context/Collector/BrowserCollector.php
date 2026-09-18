<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Priebera\ContextReporter\Context\Browser\BrowserInfoSanitizer;
use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Browser, operating system and viewport as reported by the reporter's
 * browser. Can be disabled in the privacy settings.
 *
 * @internal
 */
#[AsTaggedItem(priority: 10)]
final readonly class BrowserCollector implements ContextCollectorInterface
{
    public function __construct(
        private ExtensionSettingsProvider $settingsProvider,
        private BrowserInfoSanitizer $sanitizer,
    ) {}

    public function getSectionKey(): string
    {
        return 'browser';
    }

    public function collect(CollectionScope $scope): array
    {
        if (!$this->settingsProvider->get()->includeBrowserDetails) {
            return [];
        }
        return $this->sanitizer->sanitize($scope->request->browser);
    }
}
