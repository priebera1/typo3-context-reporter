<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Priebera\ContextReporter\Configuration\SettingsRepository;
use Priebera\ContextReporter\Configuration\SystemConfiguration;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use TYPO3\CMS\Core\Registry;

final class SettingsStoreTest extends AbstractContextReporterTestCase
{
    #[Test]
    public function moduleSettingsAreStoredInTheRegistryAndUsed(): void
    {
        $repository = $this->get(SettingsRepository::class);
        $provider = $this->get(ExtensionSettingsProvider::class);
        self::assertSame([], $repository->load());
        self::assertFalse($provider->get()->email->isUsable());
        self::assertSame(0, $provider->get()->retentionDays);

        $repository->saveSection('email', ['enabled' => true, 'recipients' => 'support@example.com'], 1);
        $repository->saveSection('reporting', ['retentionDays' => 90], 1);
        $provider->reset();

        self::assertTrue($provider->get()->email->isUsable());
        self::assertSame(90, $provider->get()->retentionDays);
        self::assertSame(['enabled' => true, 'recipients' => 'support@example.com'], $this->get(Registry::class)->get('tx_contextreporter', 'settings')['email']);
        $lastChange = $repository->getLastChange();
        self::assertSame(1, $lastChange['user'] ?? null);
        self::assertGreaterThan(time() - 60, $lastChange['time'] ?? 0);
    }

    #[Test]
    public function systemConfigurationOverridesModuleSettings(): void
    {
        $repository = $this->get(SettingsRepository::class);
        $repository->saveSection('email', ['enabled' => true, 'recipients' => 'support@example.com'], 1);
        $repository->saveSection('general', ['projectName' => 'From the module', 'environment' => 'Production'], 1);
        $this->configureExtension(['email' => ['enabled' => '0'], 'general' => ['environment' => 'Staging']]);

        $settings = $this->get(ExtensionSettingsProvider::class)->get();

        self::assertFalse($settings->email->enabled, 'The staging configuration wins over the copied database');
        self::assertSame(['support@example.com'], $settings->email->recipients);
        self::assertSame('From the module', $settings->projectName);
        self::assertSame('Staging', $settings->environment);
        self::assertSame(
            ['email.enabled', 'general.environment'],
            $this->get(SystemConfiguration::class)->getOverriddenPaths(),
        );
    }

    #[Test]
    public function rawEffectiveConfigurationKeepsEnvironmentReferences(): void
    {
        $this->get(SettingsRepository::class)->saveSection('webhook', ['enabled' => true, 'url' => 'https://hooks.example.com/abc', 'secret' => '%env(CR_TEST_SECRET)%'], 1);
        $this->configureExtension(['webhook' => ['authHeaderValue' => 'Bearer inline-token']]);

        $raw = $this->get(ExtensionSettingsProvider::class)->getRawConfiguration();

        self::assertSame('%env(CR_TEST_SECRET)%', $raw['webhook']['secret']);
        self::assertSame('Bearer inline-token', $raw['webhook']['authHeaderValue']);
        self::assertSame('https://hooks.example.com/abc', $raw['webhook']['url']);
    }

    #[Test]
    public function readingTheSettingsNeverSynchronizesTheExtensionConfiguration(): void
    {
        // Core rewrites settings.php and drops these values when it synchronizes the extension configuration
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['extension_without_template'] = ['option' => 'from additional.php'];
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['context_reporter']);
        $settingsFile = $this->instancePath . '/typo3conf/system/settings.php';
        $before = is_file($settingsFile) ? (string)file_get_contents($settingsFile) : '';

        $system = $this->get(SystemConfiguration::class);
        self::assertSame([], $system->get());
        self::assertSame([], $system->getOverriddenPaths());
        self::assertFalse($system->hasLegacyExtensionConfiguration());
        $this->get(ExtensionSettingsProvider::class)->get();

        self::assertSame(['option' => 'from additional.php'], $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['extension_without_template'] ?? null);
        self::assertSame($before, is_file($settingsFile) ? (string)file_get_contents($settingsFile) : '');
    }

    #[Test]
    public function configurationOfPreReleaseVersionsIsRecognized(): void
    {
        $system = $this->get(SystemConfiguration::class);
        self::assertFalse($system->hasLegacyExtensionConfiguration());

        $this->configureExtension([
            'general' => ['enabled' => '1', 'environment' => '', 'maxReportsPerUserPerHour' => '20', 'maxScreenshotSizeKb' => '5120', 'projectIdentifier' => '', 'projectName' => ''],
            'privacy' => ['browserDetails' => '1', 'recentBackendErrors' => '0', 'reporterEmail' => '0', 'reporterGroups' => '0', 'reporterRealName' => '0', 'reporterUid' => '1', 'reporterUsername' => '1'],
            'email' => ['enabled' => '0', 'recipients' => ''],
            'webhook' => ['enabled' => '0', 'url' => ''],
        ]);

        self::assertTrue($system->hasLegacyExtensionConfiguration());
        self::assertContains('general.maxScreenshotSizeKb', $system->getOverriddenPaths());
    }
}
