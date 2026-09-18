<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Configuration;

/**
 * Validated, immutable view of the effective Context Reporter settings.
 *
 * @internal
 */
final readonly class ExtensionSettings
{
    public function __construct(
        public bool $enabled,
        public string $projectName,
        public string $projectIdentifier,
        public string $environment,
        public int $maxScreenshotBytes,
        public int $maxReportsPerUserPerHour,
        /** Days after which reports may be removed by the cleanup; 0 keeps them forever */
        public int $retentionDays,
        public bool $includeBrowserDetails,
        public bool $includeRecentBackendErrors,
        public ReporterPrivacySettings $reporterPrivacy,
        public EmailSettings $email,
        public WebhookSettings $webhook,
    ) {}
}
