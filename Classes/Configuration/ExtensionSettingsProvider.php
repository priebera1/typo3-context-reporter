<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Configuration;

use TYPO3\CMS\Core\Core\Environment;

/**
 * The effective settings: the settings of the Context Reports module,
 * overridden by the TYPO3 system configuration.
 *
 * @internal
 */
class ExtensionSettingsProvider
{
    private ?ExtensionSettings $settings = null;

    public function __construct(
        private readonly SettingsRepository $repository,
        private readonly SystemConfiguration $systemConfiguration,
        private readonly ExtensionSettingsFactory $factory,
    ) {}

    public function get(): ExtensionSettings
    {
        if ($this->settings === null) {
            $siteName = $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? '';
            $this->settings = $this->factory->fromArray(
                $this->getRawConfiguration(),
                is_string($siteName) ? $siteName : '',
                (string)Environment::getContext(),
            );
        }
        return $this->settings;
    }

    /**
     * The merged configuration before validation. Environment references are
     * not resolved, so it can be used to describe secrets without revealing them.
     *
     * @return array<string, mixed>
     */
    public function getRawConfiguration(): array
    {
        return array_replace_recursive($this->repository->load(), $this->systemConfiguration->get());
    }

    /**
     * Forgets the settings of the current request, e.g. after they were saved.
     */
    public function reset(): void
    {
        $this->settings = null;
    }
}
