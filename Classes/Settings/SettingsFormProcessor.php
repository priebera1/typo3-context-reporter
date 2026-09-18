<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Settings;

use Priebera\ContextReporter\Configuration\EnvironmentReference;
use Priebera\ContextReporter\Configuration\ExtensionSettingsFactory;
use Priebera\ContextReporter\Delivery\Email\EmailTemplateLoader;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Validates and normalizes the submitted form of one settings section.
 *
 * Secrets and the webhook endpoint are write-only: an empty input keeps the
 * stored value, a "remove" checkbox clears it. Values set in the TYPO3 system configuration are
 * neither changed nor validated.
 *
 * @internal
 */
final class SettingsFormProcessor
{
    public const SECTIONS = ['general', 'privacy', 'reporting', 'email', 'webhook'];
    public const ENVIRONMENTS = ['Production', 'Staging', 'Development', 'Testing'];
    public const RETENTION_PRESETS = [0, 30, 90, 180, 365];
    public const MAX_RECIPIENTS = 20;

    private const PRIVACY_KEYS = ['reporterUid', 'reporterUsername', 'reporterRealName', 'reporterEmail', 'reporterGroups', 'browserDetails', 'recentBackendErrors'];
    private const PROJECT_IDENTIFIER = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D';
    private const MAX_NAME_LENGTH = 100;
    private const MAX_ENVIRONMENT_LENGTH = 50;
    private const MAX_SUBJECT_LENGTH = 255;
    private const MAX_PATH_LENGTH = 255;
    private const MAX_URL_LENGTH = 2048;
    private const MAX_SECRET_LENGTH = 1000;

    /**
     * @param array<array-key, mixed> $input Submitted fields of the section
     * @param array<array-key, mixed> $current Stored values of the section
     * @param list<string> $overridden Keys of the section set by the system configuration
     * @throws \InvalidArgumentException for unknown sections
     */
    public function process(string $section, array $input, array $current, array $overridden): SettingsFormResult
    {
        [$values, $errors] = match ($section) {
            'general' => $this->processGeneral($input),
            'privacy' => $this->processPrivacy($input),
            'reporting' => $this->processReporting($input),
            'email' => $this->processEmail($input),
            'webhook' => $this->processWebhook($input, $current),
            default => throw new \InvalidArgumentException('Unknown settings section "' . $section . '".', 1758100001),
        };

        foreach ($overridden as $key) {
            unset($errors[$key]);
            if (array_key_exists($key, $current)) {
                $values[$key] = $current[$key];
            } else {
                unset($values[$key]);
            }
        }
        return new SettingsFormResult($values, $errors);
    }

    /**
     * @param array<array-key, mixed> $input
     * @return array{array<string, mixed>, array<string, string>}
     */
    private function processGeneral(array $input): array
    {
        $errors = [];
        $projectName = $this->string($input, 'projectName');
        if (!$this->isSingleLine($projectName, self::MAX_NAME_LENGTH)) {
            $errors['projectName'] = 'error.projectName';
        }
        $projectIdentifier = $this->string($input, 'projectIdentifier');
        if ($projectIdentifier !== '' && !preg_match(self::PROJECT_IDENTIFIER, $projectIdentifier)) {
            $errors['projectIdentifier'] = 'error.projectIdentifier';
        }
        $environment = $this->string($input, 'environment');
        if ($environment === 'custom') {
            $environment = $this->string($input, 'environmentCustom');
            if ($environment === '' || !$this->isSingleLine($environment, self::MAX_ENVIRONMENT_LENGTH)) {
                $errors['environment'] = 'error.environment';
            }
        } elseif ($environment !== '' && !in_array($environment, self::ENVIRONMENTS, true)) {
            $errors['environment'] = 'error.environment';
        }

        return [[
            'enabled' => $this->bool($input, 'enabled'),
            'projectName' => $projectName,
            'projectIdentifier' => $projectIdentifier,
            'environment' => $environment,
        ], $errors];
    }

    /**
     * @param array<array-key, mixed> $input
     * @return array{array<string, mixed>, array<string, string>}
     */
    private function processPrivacy(array $input): array
    {
        $values = [];
        foreach (self::PRIVACY_KEYS as $key) {
            $values[$key] = $this->bool($input, $key);
        }
        return [$values, []];
    }

    /**
     * @param array<array-key, mixed> $input
     * @return array{array<string, mixed>, array<string, string>}
     */
    private function processReporting(array $input): array
    {
        $errors = [];
        $screenshotKb = $this->int($input, 'maxScreenshotSizeKb', ExtensionSettingsFactory::DEFAULT_SCREENSHOT_KB);
        if ($screenshotKb === null || $screenshotKb < 100 || $screenshotKb > ExtensionSettingsFactory::MAX_SCREENSHOT_KB) {
            $errors['maxScreenshotSizeKb'] = 'error.maxScreenshotSizeKb';
        }
        $reportsPerHour = $this->int($input, 'maxReportsPerUserPerHour', ExtensionSettingsFactory::DEFAULT_REPORTS_PER_HOUR);
        if ($reportsPerHour === null || $reportsPerHour < 0 || $reportsPerHour > ExtensionSettingsFactory::MAX_REPORTS_PER_HOUR) {
            $errors['maxReportsPerUserPerHour'] = 'error.maxReportsPerUserPerHour';
        }
        $retention = $this->string($input, 'retention');
        if ($retention === 'custom') {
            $retentionDays = $this->int($input, 'retentionDays', 0);
            if ($retentionDays === null || $retentionDays < 1 || $retentionDays > ExtensionSettingsFactory::MAX_RETENTION_DAYS) {
                $errors['retentionDays'] = 'error.retentionDays';
            }
        } else {
            $retentionDays = $retention === '' ? 0 : (int)$retention;
            if (!in_array($retention, array_map(strval(...), self::RETENTION_PRESETS), true) && $retention !== '') {
                $errors['retentionDays'] = 'error.retentionDays';
            }
        }

        return [[
            'maxScreenshotSizeKb' => $screenshotKb ?? ExtensionSettingsFactory::DEFAULT_SCREENSHOT_KB,
            'maxReportsPerUserPerHour' => $reportsPerHour ?? ExtensionSettingsFactory::DEFAULT_REPORTS_PER_HOUR,
            'retentionDays' => max(0, (int)$retentionDays),
        ], $errors];
    }

    /**
     * @param array<array-key, mixed> $input
     * @return array{array<string, mixed>, array<string, string>}
     */
    private function processEmail(array $input): array
    {
        $errors = [];
        $enabled = $this->bool($input, 'enabled');

        $recipients = [];
        $invalidRecipient = false;
        foreach (preg_split('/[\s,;]+/', $this->string($input, 'recipients')) ?: [] as $candidate) {
            if ($candidate === '') {
                continue;
            }
            if (!GeneralUtility::validEmail($candidate)) {
                $invalidRecipient = true;
            } elseif (!in_array($candidate, $recipients, true)) {
                $recipients[] = $candidate;
            }
        }
        if ($invalidRecipient || count($recipients) > self::MAX_RECIPIENTS || ($enabled && $recipients === [])) {
            $errors['recipients'] = $invalidRecipient ? 'error.recipients' : ($recipients === [] ? 'error.recipientsRequired' : 'error.recipientsTooMany');
        }

        $senderAddress = $this->string($input, 'senderAddress');
        if ($senderAddress !== '' && !GeneralUtility::validEmail($senderAddress)) {
            $errors['senderAddress'] = 'error.senderAddress';
        }
        $senderName = $this->string($input, 'senderName');
        if (!$this->isSingleLine($senderName, self::MAX_NAME_LENGTH)) {
            $errors['senderName'] = 'error.senderName';
        }
        $subject = $this->string($input, 'subject');
        if (!$this->isSingleLine($subject, self::MAX_SUBJECT_LENGTH)) {
            $errors['subject'] = 'error.subject';
        }
        $bodyTemplate = $this->string($input, 'bodyTemplate');
        if ($bodyTemplate !== '' && !$this->isReadableTemplate($bodyTemplate)) {
            $errors['bodyTemplate'] = 'error.bodyTemplate';
        }

        return [[
            'enabled' => $enabled,
            'recipients' => implode(', ', $recipients),
            'senderAddress' => $senderAddress,
            'senderName' => $senderName,
            'subject' => $subject !== '' ? $subject : ExtensionSettingsFactory::DEFAULT_SUBJECT,
            'bodyTemplate' => $bodyTemplate !== '' ? $bodyTemplate : ExtensionSettingsFactory::DEFAULT_BODY_TEMPLATE,
            'attachScreenshot' => $this->bool($input, 'attachScreenshot'),
            'attachJson' => $this->bool($input, 'attachJson'),
            'replyToReporter' => $this->bool($input, 'replyToReporter'),
        ], $errors];
    }

    /**
     * @param array<array-key, mixed> $input
     * @param array<array-key, mixed> $current
     * @return array{array<string, mixed>, array<string, string>}
     */
    private function processWebhook(array $input, array $current): array
    {
        $errors = [];
        $enabled = $this->bool($input, 'enabled');
        $allowInsecureHttp = $this->bool($input, 'allowInsecureHttp');

        // Endpoints often embed tokens, so the stored one is treated like a secret
        $url = $this->secret($input, $current, 'url');
        if ($url === '') {
            if ($enabled) {
                $errors['url'] = 'error.urlRequired';
            }
        } elseif (!EnvironmentReference::isReference($url) && !$this->isValidEndpoint($url, $allowInsecureHttp)) {
            $errors['url'] = 'error.url';
        }

        $secret = $this->secret($input, $current, 'secret');
        $authHeaderValue = $this->secret($input, $current, 'authHeaderValue');
        foreach (['secret' => $secret, 'authHeaderValue' => $authHeaderValue] as $key => $value) {
            if (strlen($value) > self::MAX_SECRET_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                $errors[$key] = 'error.' . $key;
            }
        }

        $authHeaderName = $this->string($input, 'authHeaderName');
        if ($authHeaderName === '') {
            $authHeaderName = 'Authorization';
        } elseif (!preg_match(ExtensionSettingsFactory::HEADER_NAME_PATTERN, $authHeaderName)) {
            $errors['authHeaderName'] = 'error.authHeaderName';
        }

        $timeout = $this->int($input, 'timeout', 10);
        if ($timeout === null || $timeout < 1 || $timeout > 30) {
            $errors['timeout'] = 'error.timeout';
        }

        return [[
            'enabled' => $enabled,
            'url' => $url,
            'secret' => $secret,
            'authHeaderName' => $authHeaderName,
            'authHeaderValue' => $authHeaderValue,
            'includeScreenshot' => $this->bool($input, 'includeScreenshot'),
            'timeout' => $timeout ?? 10,
            'allowInsecureHttp' => $allowInsecureHttp,
        ], $errors];
    }

    /**
     * @param array<array-key, mixed> $input
     * @param array<array-key, mixed> $current
     */
    private function secret(array $input, array $current, string $key): string
    {
        if ($this->bool($input, $key . 'Remove')) {
            return '';
        }
        $submitted = $this->string($input, $key);
        if ($submitted !== '') {
            return $submitted;
        }
        return is_string($current[$key] ?? null) ? $current[$key] : '';
    }

    private function isValidEndpoint(string $url, bool $allowInsecureHttp): bool
    {
        if (strlen($url) > self::MAX_URL_LENGTH || preg_match('/\s/', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        $scheme = strtolower(is_array($parts) ? (string)($parts['scheme'] ?? '') : '');
        return is_array($parts)
            && ($parts['host'] ?? '') !== ''
            && ($scheme === 'https' || ($allowInsecureHttp && $scheme === 'http'));
    }

    private function isReadableTemplate(string $path): bool
    {
        if (strlen($path) > self::MAX_PATH_LENGTH || !EmailTemplateLoader::isAllowedPath($path)) {
            return false;
        }
        $absolutePath = GeneralUtility::getFileAbsFileName($path);
        return $absolutePath !== '' && is_file($absolutePath) && is_readable($absolutePath);
    }

    private function isSingleLine(string $value, int $maxLength): bool
    {
        return mb_strlen($value) <= $maxLength && !preg_match('/[\x00-\x1F\x7F]/', $value);
    }

    /**
     * @param array<array-key, mixed> $input
     */
    private function string(array $input, string $key): string
    {
        $value = $input[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Checkboxes follow a hidden field with the value "0"; PHP keeps the last value.
     *
     * @param array<array-key, mixed> $input
     */
    private function bool(array $input, string $key): bool
    {
        $value = $input[$key] ?? '0';
        if (is_array($value)) {
            $value = end($value);
        }
        return $value === '1' || $value === 1 || $value === true;
    }

    /**
     * @param array<array-key, mixed> $input
     */
    private function int(array $input, string $key, int $default): ?int
    {
        $value = $input[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        return is_string($value) && preg_match('/^-?\d{1,6}$/D', trim($value)) ? (int)trim($value) : null;
    }
}
