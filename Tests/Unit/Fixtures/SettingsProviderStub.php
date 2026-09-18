<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Fixtures;

use Priebera\ContextReporter\Configuration\ExtensionSettings;
use Priebera\ContextReporter\Configuration\ExtensionSettingsFactory;
use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;

/**
 * Serves settings created from a raw configuration array.
 */
final class SettingsProviderStub extends ExtensionSettingsProvider
{
    private readonly ExtensionSettings $fixedSettings;

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, string> $environment Environment variables for %env()% references
     */
    public function __construct(
        private readonly array $configuration,
        array $environment = [],
    ) {
        $this->fixedSettings = (new ExtensionSettingsFactory(static fn(string $name): string|false => $environment[$name] ?? false))
            ->fromArray($configuration, 'Test project', 'Testing');
    }

    public function get(): ExtensionSettings
    {
        return $this->fixedSettings;
    }

    public function getRawConfiguration(): array
    {
        return $this->configuration;
    }
}
