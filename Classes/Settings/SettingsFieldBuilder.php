<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Settings;

use Priebera\ContextReporter\Configuration\EnvironmentReference;
use Priebera\ContextReporter\Configuration\ExtensionSettings;
use Priebera\ContextReporter\Configuration\ExtensionSettingsFactory;
use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Priebera\ContextReporter\Configuration\SystemConfiguration;
use Priebera\ContextReporter\Delivery\Email\MailConfigurationInspector;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Prepares the form fields of a settings section for the template.
 *
 * Secrets and the webhook endpoint are never rendered: the fields stay empty
 * and only show the state of the stored value.
 *
 * @internal
 */
final readonly class SettingsFieldBuilder
{
    private const LABELS = 'LLL:EXT:context_reporter/Resources/Private/Language/locallang_module.xlf:';

    /**
     * Field name => type, in the order of the form.
     */
    private const FIELDS = [
        'general' => [
            'enabled' => 'switch',
            'projectName' => 'text',
            'projectIdentifier' => 'text',
            'environment' => 'environment',
        ],
        'privacy' => [
            'reporterHeading' => 'heading',
            'reporterUid' => 'switch',
            'reporterUsername' => 'switch',
            'reporterRealName' => 'switch',
            'reporterEmail' => 'switch',
            'reporterGroups' => 'switch',
            'technicalHeading' => 'heading',
            'browserDetails' => 'switch',
            'recentBackendErrors' => 'switch',
        ],
        'reporting' => [
            'maxScreenshotSizeKb' => 'number',
            'maxReportsPerUserPerHour' => 'number',
            'retentionDays' => 'retention',
        ],
        'email' => [
            'enabled' => 'switch',
            'recipients' => 'textarea',
            'senderAddress' => 'email',
            'senderName' => 'text',
            'subject' => 'text',
            'bodyTemplate' => 'text',
            'attachScreenshot' => 'switch',
            'attachJson' => 'switch',
            'replyToReporter' => 'switch',
        ],
        'webhook' => [
            'enabled' => 'switch',
            'url' => 'endpoint',
            'secret' => 'secret',
            'authHeaderName' => 'text',
            'authHeaderValue' => 'secret',
            'timeout' => 'number',
            'includeScreenshot' => 'switch',
            'allowInsecureHttp' => 'switch',
        ],
    ];

    private const LIMITS = [
        'projectName' => ['maxlength' => 100],
        'projectIdentifier' => ['maxlength' => 64],
        'maxScreenshotSizeKb' => ['min' => 100, 'max' => ExtensionSettingsFactory::MAX_SCREENSHOT_KB],
        'maxReportsPerUserPerHour' => ['min' => 0, 'max' => ExtensionSettingsFactory::MAX_REPORTS_PER_HOUR],
        'senderAddress' => ['maxlength' => 255],
        'senderName' => ['maxlength' => 100],
        'subject' => ['maxlength' => 255],
        'bodyTemplate' => ['maxlength' => 255],
        'authHeaderName' => ['maxlength' => 100],
        'timeout' => ['min' => 1, 'max' => 30],
    ];

    public function __construct(
        private ExtensionSettingsProvider $settingsProvider,
        private SystemConfiguration $systemConfiguration,
        private MailConfigurationInspector $mailConfiguration,
    ) {}

    /**
     * @return list<string>
     */
    public static function getSections(): array
    {
        return array_keys(self::FIELDS);
    }

    /**
     * @param array<array-key, mixed>|null $submitted Input of a rejected form, shown again except for secrets
     * @param array<string, string> $errors Field name => label key
     * @return list<array<string, mixed>>
     */
    public function build(string $section, LanguageService $languageService, ?array $submitted = null, array $errors = []): array
    {
        if (!isset(self::FIELDS[$section])) {
            throw new \InvalidArgumentException('Unknown settings section "' . $section . '".', 1758300001);
        }
        $settings = $this->settingsProvider->get();
        $raw = $this->settingsProvider->getRawConfiguration()[$section] ?? [];
        $raw = is_array($raw) ? $raw : [];
        $pinned = $this->systemConfiguration->getOverriddenKeys($section);

        $fields = [];
        foreach (self::FIELDS[$section] as $name => $type) {
            $isPinned = in_array($name, $pinned, true);
            $field = [
                'name' => $name,
                'type' => $type,
                'id' => 'cr-settings-' . $section . '-' . $name,
                'inputName' => 'settings[' . $name . ']',
                'label' => $this->translate($languageService, 'settings.' . $section . '.' . $name),
                'description' => $this->translate($languageService, 'settings.' . $section . '.' . $name . '.description'),
                'value' => '',
                'checked' => false,
                'placeholder' => '',
                'pinned' => $isPinned,
                'error' => isset($errors[$name]) ? $this->translate($languageService, 'settings.' . $errors[$name]) : '',
            ] + (self::LIMITS[$name] ?? []);

            $value = $this->getValue($section, $name, $settings, $raw);
            // A rejected form shows the submitted values again, but never secrets
            if ($submitted !== null && !$isPinned && !in_array($type, ['secret', 'endpoint', 'heading'], true)) {
                $value = $this->getSubmittedValue($type, $name, $submitted, $value);
            }
            $field = match ($type) {
                'switch' => ['checked' => (bool)$value] + $field,
                'environment' => $this->buildEnvironmentField($field, is_array($value) ? $value : (is_string($value) ? $value : ''), $languageService),
                'retention' => $this->buildRetentionField($field, $value, $languageService),
                'secret', 'endpoint' => $this->buildSecretField($field, $section, $name, $settings, $raw, $languageService),
                'heading' => $field,
                default => ['value' => is_scalar($value) ? (string)$value : ''] + $field,
            };
            $fields[] = $this->addPlaceholders($section, $field, $languageService);
        }
        return $fields;
    }

    /**
     * The effective value of a field; secrets are never returned.
     *
     * @param array<array-key, mixed> $raw
     */
    private function getValue(string $section, string $name, ExtensionSettings $settings, array $raw): mixed
    {
        $string = static fn(string $key): string => is_scalar($raw[$key] ?? null) ? trim((string)$raw[$key]) : '';
        return match ($section . '.' . $name) {
            'general.enabled' => $settings->enabled,
            'general.projectName' => $string('projectName'),
            'general.projectIdentifier' => $string('projectIdentifier'),
            'general.environment' => $string('environment'),
            'privacy.reporterUid' => $settings->reporterPrivacy->includeUid,
            'privacy.reporterUsername' => $settings->reporterPrivacy->includeUsername,
            'privacy.reporterRealName' => $settings->reporterPrivacy->includeRealName,
            'privacy.reporterEmail' => $settings->reporterPrivacy->includeEmail,
            'privacy.reporterGroups' => $settings->reporterPrivacy->includeGroups,
            'privacy.browserDetails' => $settings->includeBrowserDetails,
            'privacy.recentBackendErrors' => $settings->includeRecentBackendErrors,
            'reporting.maxScreenshotSizeKb' => intdiv($settings->maxScreenshotBytes, 1024),
            'reporting.maxReportsPerUserPerHour' => $settings->maxReportsPerUserPerHour,
            'reporting.retentionDays' => $settings->retentionDays,
            'email.enabled' => $settings->email->enabled,
            'email.recipients' => $string('recipients'),
            'email.senderAddress' => $string('senderAddress'),
            'email.senderName' => $string('senderName'),
            'email.subject' => $string('subject') ?: ExtensionSettingsFactory::DEFAULT_SUBJECT,
            'email.bodyTemplate' => $string('bodyTemplate') ?: ExtensionSettingsFactory::DEFAULT_BODY_TEMPLATE,
            'email.attachScreenshot' => $settings->email->attachScreenshot,
            'email.attachJson' => $settings->email->attachJson,
            'email.replyToReporter' => $settings->email->replyToReporter,
            'webhook.enabled' => $settings->webhook->enabled,
            'webhook.authHeaderName' => $string('authHeaderName') ?: 'Authorization',
            'webhook.timeout' => $settings->webhook->timeoutSeconds,
            'webhook.includeScreenshot' => $settings->webhook->includeScreenshot,
            'webhook.allowInsecureHttp' => $settings->webhook->allowInsecureHttp,
            default => null,
        };
    }

    /**
     * @param array<array-key, mixed> $submitted
     */
    private function getSubmittedValue(string $type, string $name, array $submitted, mixed $default): mixed
    {
        $read = static function (string $key) use ($submitted): ?string {
            $value = $submitted[$key] ?? null;
            if (is_array($value)) {
                $value = end($value);
            }
            return is_string($value) ? trim($value) : null;
        };
        return match ($type) {
            'switch' => $read($name) === '1',
            'environment' => $read('environment') === 'custom' ? ['custom', $read('environmentCustom') ?? ''] : ($read('environment') ?? $default),
            'retention' => $read('retention') === 'custom' ? ['custom', $read('retentionDays') ?? ''] : ($read('retention') ?? $default),
            default => $read($name) ?? $default,
        };
    }

    /**
     * @param array<string, mixed> $field
     * @param string|array<array-key, mixed> $value The environment, or "custom" and the custom name of a rejected form
     * @return array<string, mixed>
     */
    private function buildEnvironmentField(array $field, string|array $value, LanguageService $languageService): array
    {
        [$selected, $custom] = is_array($value) ? [(string)($value[0] ?? ''), (string)($value[1] ?? '')] : [$value, ''];
        if ($selected !== '' && $selected !== 'custom' && !in_array($selected, SettingsFormProcessor::ENVIRONMENTS, true)) {
            [$selected, $custom] = ['custom', $selected];
        }
        $options = [[
            'value' => '',
            'label' => sprintf($this->translate($languageService, 'settings.general.environment.automatic'), (string)Environment::getContext()),
        ]];
        foreach (SettingsFormProcessor::ENVIRONMENTS as $environment) {
            $options[] = ['value' => $environment, 'label' => $this->translate($languageService, 'settings.general.environment.' . $environment)];
        }
        $options[] = ['value' => 'custom', 'label' => $this->translate($languageService, 'settings.general.environment.custom')];
        foreach ($options as $index => $option) {
            $options[$index]['selected'] = $option['value'] === $selected;
        }
        return [
            'options' => $options,
            'custom' => [
                'id' => $field['id'] . '-custom',
                'inputName' => 'settings[environmentCustom]',
                'label' => $this->translate($languageService, 'settings.general.environmentCustom'),
                'value' => $custom,
                'maxlength' => 50,
            ],
        ] + $field;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function buildRetentionField(array $field, mixed $value, LanguageService $languageService): array
    {
        if (is_array($value)) {
            [$selected, $custom] = $value;
        } else {
            $days = is_numeric($value) ? (string)(int)$value : '';
            $isPreset = in_array((int)$days, SettingsFormProcessor::RETENTION_PRESETS, true) && $days !== '';
            [$selected, $custom] = $isPreset ? [$days, ''] : ['custom', $days];
        }
        $options = [];
        foreach ([...SettingsFormProcessor::RETENTION_PRESETS, 'custom'] as $preset) {
            $options[] = [
                'value' => (string)$preset,
                'label' => $this->translate($languageService, 'settings.reporting.retention.' . $preset),
                'selected' => (string)$preset === (string)$selected,
            ];
        }
        return [
            'inputName' => 'settings[retention]',
            'options' => $options,
            'custom' => [
                'id' => $field['id'] . '-custom',
                'inputName' => 'settings[retentionDays]',
                'label' => $this->translate($languageService, 'settings.reporting.retentionCustom'),
                'value' => (string)$custom,
                'min' => 1,
                'max' => ExtensionSettingsFactory::MAX_RETENTION_DAYS,
            ],
        ] + $field;
    }

    /**
     * @param array<string, mixed> $field
     * @param array<array-key, mixed> $raw
     * @return array<string, mixed>
     */
    private function buildSecretField(array $field, string $section, string $name, ExtensionSettings $settings, array $raw, LanguageService $languageService): array
    {
        $rawValue = is_scalar($raw[$name] ?? null) ? trim((string)$raw[$name]) : '';
        $effective = match ($name) {
            'url' => $settings->webhook->url,
            'secret' => $settings->webhook->secret,
            'authHeaderValue' => $settings->webhook->authHeaderValue,
            default => '',
        };
        $state = SecretState::of($rawValue, $effective);
        $variable = EnvironmentReference::getVariableName($rawValue) ?? '';
        $masked = '';
        if ($name === 'url' && $state === SecretState::Configured) {
            $parts = parse_url($effective);
            $hasPath = is_array($parts) && (!in_array($parts['path'] ?? '', ['', '/'], true) || isset($parts['query']));
            $masked = $settings->webhook->getDisplayTarget() . ($hasPath ? '/…' : '');
        }

        return [
            'inputName' => 'settings[' . $name . ']',
            'removeName' => 'settings[' . $name . 'Remove]',
            'removeId' => $field['id'] . '-remove',
            'removeLabel' => $this->translate($languageService, $name === 'url' ? 'settings.webhook.url.remove' : 'settings.secret.remove'),
            'canRemove' => $state !== SecretState::NotConfigured && !$field['pinned'],
            'state' => $state->value,
            'stateLabel' => sprintf($this->translate($languageService, 'settings.secret.state.' . $state->value), $variable),
            'stateClass' => match ($state) {
                SecretState::Configured, SecretState::Environment => 'success',
                SecretState::NotConfigured => 'default',
                SecretState::EnvironmentUnavailable, SecretState::Invalid => 'danger',
            },
            'masked' => $masked,
            'inputType' => $name === 'url' ? 'text' : 'password',
            'maxlength' => $name === 'url' ? 2048 : 1000,
            'placeholder' => $this->translate($languageService, $state === SecretState::NotConfigured ? 'settings.secret.placeholderNew' : 'settings.secret.placeholderKeep'),
        ] + $field;
    }

    /**
     * Placeholders and descriptions that show the values used when a field is empty.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function addPlaceholders(string $section, array $field, LanguageService $languageService): array
    {
        switch ($section . '.' . $field['name']) {
            case 'general.projectName':
                $siteName = $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? '';
                $fallback = is_string($siteName) && $siteName !== '' ? $siteName : 'TYPO3';
                $field['placeholder'] = $fallback;
                $field['description'] = sprintf((string)$field['description'], $fallback);
                break;
            case 'general.environment':
                $field['description'] = sprintf((string)$field['description'], (string)Environment::getContext());
                break;
            case 'email.senderAddress':
                $transport = $this->mailConfiguration->describe();
                $field['placeholder'] = $transport->defaultSenderAddress;
                $field['description'] = sprintf((string)$field['description'], $transport->defaultSenderAddress);
                break;
            case 'general.projectIdentifier':
                $field['placeholder'] = 'my-project';
                break;
        }
        return $field;
    }

    private function translate(LanguageService $languageService, string $key): string
    {
        return $languageService->sL(self::LABELS . $key);
    }
}
