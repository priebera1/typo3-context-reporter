<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Configuration;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Turns the raw configuration (the settings of the Context Reports module,
 * overridden by the TYPO3 system configuration) into validated settings.
 * Invalid values fall back to safe defaults instead of failing, so a typo
 * never breaks the backend.
 *
 * Secret-like values may reference environment variables with the same syntax
 * TYPO3 uses in YAML files: %env(NAME)%
 *
 * @internal
 */
final class ExtensionSettingsFactory
{
    public const DEFAULT_SUBJECT = '[{project.name}] {report.title} ({report.id})';
    public const DEFAULT_BODY_TEMPLATE = 'EXT:context_reporter/Resources/Private/Templates/Email/Report.txt';

    /**
     * Screenshots are stored in a MEDIUMBLOB column (16 MB).
     */
    public const MAX_SCREENSHOT_KB = 15360;
    public const DEFAULT_SCREENSHOT_KB = 5120;
    public const DEFAULT_REPORTS_PER_HOUR = 20;
    public const MAX_REPORTS_PER_HOUR = 1000;
    public const MAX_RETENTION_DAYS = 3650;
    public const HEADER_NAME_PATTERN = '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,100}$/D';

    /**
     * @var \Closure(string): (string|false)
     */
    private readonly \Closure $environmentReader;

    /**
     * @param (callable(string): (string|false))|null $environmentReader
     */
    public function __construct(?callable $environmentReader = null)
    {
        $this->environmentReader = $environmentReader !== null
            ? $environmentReader(...)
            : static fn(string $name): string|false => getenv($name);
    }

    /**
     * @param array<array-key, mixed> $configuration
     */
    public function fromArray(array $configuration, string $fallbackProjectName, string $fallbackEnvironment): ExtensionSettings
    {
        $general = $this->section($configuration, 'general');
        $privacy = $this->section($configuration, 'privacy');
        $reporting = $this->section($configuration, 'reporting');

        // Project values end up in email subjects and headers
        $projectName = $this->singleLine($this->string($general, 'projectName'));
        $environment = $this->singleLine($this->string($general, 'environment'));

        return new ExtensionSettings(
            enabled: $this->bool($general, 'enabled', true),
            projectName: $projectName !== '' ? $projectName : ($fallbackProjectName !== '' ? $fallbackProjectName : 'TYPO3'),
            projectIdentifier: $this->singleLine($this->string($general, 'projectIdentifier')),
            environment: $environment !== '' ? $environment : $fallbackEnvironment,
            maxScreenshotBytes: $this->int($reporting, 'maxScreenshotSizeKb', self::DEFAULT_SCREENSHOT_KB, 100, self::MAX_SCREENSHOT_KB) * 1024,
            maxReportsPerUserPerHour: $this->int($reporting, 'maxReportsPerUserPerHour', self::DEFAULT_REPORTS_PER_HOUR, 0, self::MAX_REPORTS_PER_HOUR),
            retentionDays: $this->int($reporting, 'retentionDays', 0, 0, self::MAX_RETENTION_DAYS),
            includeBrowserDetails: $this->bool($privacy, 'browserDetails', true),
            includeRecentBackendErrors: $this->bool($privacy, 'recentBackendErrors', false),
            reporterPrivacy: new ReporterPrivacySettings(
                includeUid: $this->bool($privacy, 'reporterUid', true),
                includeUsername: $this->bool($privacy, 'reporterUsername', true),
                includeRealName: $this->bool($privacy, 'reporterRealName', false),
                includeEmail: $this->bool($privacy, 'reporterEmail', false),
                includeGroups: $this->bool($privacy, 'reporterGroups', false),
            ),
            email: $this->createEmailSettings($this->section($configuration, 'email')),
            webhook: $this->createWebhookSettings($this->section($configuration, 'webhook')),
        );
    }

    /**
     * @param array<array-key, mixed> $email
     */
    private function createEmailSettings(array $email): EmailSettings
    {
        $recipients = [];
        foreach (preg_split('/[\s,;]+/', $this->string($email, 'recipients')) ?: [] as $candidate) {
            if ($candidate !== '' && GeneralUtility::validEmail($candidate) && !in_array($candidate, $recipients, true)) {
                $recipients[] = $candidate;
            }
        }
        $senderAddress = $this->string($email, 'senderAddress');
        $subject = $this->string($email, 'subject');
        $bodyTemplate = $this->string($email, 'bodyTemplate');

        return new EmailSettings(
            enabled: $this->bool($email, 'enabled', false),
            recipients: $recipients,
            senderAddress: GeneralUtility::validEmail($senderAddress) ? $senderAddress : '',
            senderName: $this->singleLine($this->string($email, 'senderName')),
            subjectTemplate: $subject !== '' ? $subject : self::DEFAULT_SUBJECT,
            bodyTemplateFile: $bodyTemplate !== '' ? $bodyTemplate : self::DEFAULT_BODY_TEMPLATE,
            attachScreenshot: $this->bool($email, 'attachScreenshot', true),
            attachJson: $this->bool($email, 'attachJson', true),
            replyToReporter: $this->bool($email, 'replyToReporter', false),
        );
    }

    /**
     * @param array<array-key, mixed> $webhook
     */
    private function createWebhookSettings(array $webhook): WebhookSettings
    {
        $allowInsecureHttp = $this->bool($webhook, 'allowInsecureHttp', false);
        $headerName = $this->string($webhook, 'authHeaderName');
        $headerValue = $this->resolve($this->string($webhook, 'authHeaderValue'));
        if ($headerName === '') {
            $headerName = 'Authorization';
        }
        if (!preg_match(self::HEADER_NAME_PATTERN, $headerName) || preg_match('/[\r\n\0]/', $headerValue)) {
            $headerName = '';
            $headerValue = '';
        }
        if ($headerValue === '') {
            $headerName = '';
        }

        return new WebhookSettings(
            enabled: $this->bool($webhook, 'enabled', false),
            url: $this->sanitizeUrl($this->resolve($this->string($webhook, 'url')), $allowInsecureHttp),
            secret: $this->resolve($this->string($webhook, 'secret')),
            authHeaderName: $headerName,
            authHeaderValue: $headerValue,
            includeScreenshot: $this->bool($webhook, 'includeScreenshot', true),
            timeoutSeconds: $this->int($webhook, 'timeout', 10, 1, 30),
            allowInsecureHttp: $allowInsecureHttp,
        );
    }

    private function sanitizeUrl(string $url, bool $allowInsecureHttp): string
    {
        if ($url === '' || preg_match('/\s/', $url)) {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['host'] ?? '') === '') {
            return '';
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'https' && !($allowInsecureHttp && $scheme === 'http')) {
            return '';
        }
        return filter_var($url, FILTER_VALIDATE_URL) === false ? '' : $url;
    }

    private function resolve(string $value): string
    {
        $variableName = EnvironmentReference::getVariableName($value);
        if ($variableName === null) {
            return $value;
        }
        $resolved = ($this->environmentReader)($variableName);
        return is_string($resolved) ? trim($resolved) : '';
    }

    private function singleLine(string $value): string
    {
        return trim((string)preg_replace('/[\r\n\t]+/', ' ', $value));
    }

    /**
     * @param array<array-key, mixed> $configuration
     * @return array<array-key, mixed>
     */
    private function section(array $configuration, string $name): array
    {
        $section = $configuration[$name] ?? null;
        return is_array($section) ? $section : [];
    }

    /**
     * @param array<array-key, mixed> $section
     */
    private function string(array $section, string $key): string
    {
        $value = $section[$key] ?? '';
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * @param array<array-key, mixed> $section
     */
    private function bool(array $section, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $section) || !is_scalar($section[$key]) || $section[$key] === '') {
            return $default;
        }
        return (bool)$section[$key];
    }

    /**
     * @param array<array-key, mixed> $section
     */
    private function int(array $section, string $key, int $default, int $min, int $max): int
    {
        $value = $section[$key] ?? null;
        if (!is_numeric($value)) {
            return $default;
        }
        return max($min, min($max, (int)$value));
    }
}
