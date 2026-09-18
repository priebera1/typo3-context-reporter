<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Security;

use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Priebera\ContextReporter\Delivery\Email\MailConfigurationInspector;

/**
 * Configured credentials that must be redacted from logged and displayed
 * error messages: webhook secrets and endpoint, mail transport credentials
 * and database passwords. Database user names are too common to redact.
 *
 * @internal
 */
final readonly class ConfiguredSecrets
{
    public function __construct(
        private ExtensionSettingsProvider $settingsProvider,
        private MailConfigurationInspector $mailConfiguration,
    ) {}

    /**
     * @return list<string>
     */
    public function get(): array
    {
        $secrets = [...$this->settingsProvider->get()->webhook->getSecrets(), ...$this->mailConfiguration->getSecrets()];
        $connections = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'] ?? [];
        foreach (is_array($connections) ? $connections : [] as $connection) {
            if (is_array($connection) && is_string($connection['password'] ?? null)) {
                $secrets[] = $connection['password'];
            }
        }
        return array_values(array_unique(array_filter($secrets, static fn(string $secret): bool => $secret !== '')));
    }
}
