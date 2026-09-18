<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Configuration;

/**
 * Settings from the TYPO3 system configuration,
 * $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['context_reporter'] (for example
 * in config/system/additional.php, per environment). Every value set there
 * overrides the setting of the Context Reports module and cannot be changed
 * in the module, so deployments can pin values, e.g. disable email delivery
 * on a staging system that runs on a copy of the production database.
 *
 * @internal
 */
final readonly class SystemConfiguration
{
    /**
     * Keys that only pre-release versions stored in the "general" section of
     * the former extension configuration form.
     */
    private const LEGACY_KEYS = ['maxScreenshotSizeKb', 'maxReportsPerUserPerHour'];

    /**
     * Read directly: ExtensionConfiguration::get() synchronizes the configuration of
     * all extensions when an extension has none. Without an ext_conf_template.txt
     * that would happen on every request, rewrite settings.php and drop values set
     * in additional.php.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'][ExtensionInfo::EXTENSION_KEY] ?? null;
        return is_array($configuration) ? $configuration : [];
    }

    /**
     * @return list<string> Paths like "email.enabled", sorted
     */
    public function getOverriddenPaths(): array
    {
        $paths = [];
        foreach ($this->get() as $section => $values) {
            if (!is_array($values)) {
                $paths[] = (string)$section;
                continue;
            }
            foreach (array_keys($values) as $key) {
                $paths[] = $section . '.' . $key;
            }
        }
        sort($paths);
        return $paths;
    }

    public function isOverridden(string $section, string $key): bool
    {
        return in_array($key, $this->getOverriddenKeys($section), true);
    }

    /**
     * @return list<string> Keys of one section, e.g. ["enabled"] for "email.enabled"
     */
    public function getOverriddenKeys(string $section): array
    {
        $values = $this->get()[$section] ?? null;
        return is_array($values) ? array_map(strval(...), array_keys($values)) : [];
    }

    /**
     * Whether the system configuration still contains the extension
     * configuration written by the former settings form of pre-release
     * versions. Its values override the module settings.
     */
    public function hasLegacyExtensionConfiguration(): bool
    {
        $general = $this->get()['general'] ?? null;
        return is_array($general) && array_intersect(self::LEGACY_KEYS, array_keys($general)) !== [];
    }
}
