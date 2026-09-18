<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Configuration;

use TYPO3\CMS\Core\Registry;

/**
 * Persists the settings managed in System > Context Reports > Settings in
 * the TYPO3 registry, one array per settings section (general, privacy,
 * reporting, email, webhook). Values set in the TYPO3 system configuration
 * override them, see SystemConfiguration.
 *
 * @internal
 */
class SettingsRepository
{
    public const REGISTRY_NAMESPACE = 'tx_contextreporter';
    private const SETTINGS_KEY = 'settings';
    private const CHANGE_KEY = 'settingsChanged';

    public function __construct(
        private readonly Registry $registry,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function load(): array
    {
        $settings = $this->registry->get(self::REGISTRY_NAMESPACE, self::SETTINGS_KEY, []);
        if (!is_array($settings)) {
            return [];
        }
        return array_filter($settings, static fn(mixed $section, mixed $name): bool => is_string($name) && is_array($section), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function saveSection(string $section, array $values, int $userId): void
    {
        $this->saveSections([$section => $values], $userId);
    }

    /**
     * @param array<string, array<string, mixed>> $sections
     */
    public function saveSections(array $sections, int $userId): void
    {
        $this->registry->set(self::REGISTRY_NAMESPACE, self::SETTINGS_KEY, array_replace($this->load(), $sections));
        $this->registry->set(self::REGISTRY_NAMESPACE, self::CHANGE_KEY, ['time' => time(), 'user' => $userId]);
    }

    /**
     * @return array{time?: int, user?: int}
     */
    public function getLastChange(): array
    {
        $change = $this->registry->get(self::REGISTRY_NAMESPACE, self::CHANGE_KEY, []);
        if (!is_array($change)) {
            return [];
        }
        return array_filter([
            'time' => is_int($change['time'] ?? null) ? $change['time'] : null,
            'user' => is_int($change['user'] ?? null) ? $change['user'] : null,
        ], static fn(?int $value): bool => $value !== null);
    }
}
