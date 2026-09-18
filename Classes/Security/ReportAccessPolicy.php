<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Security;

use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Priebera\ContextReporter\Domain\Report;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Who may create and read reports.
 *
 * Reporting is available to every backend user unless the extension is
 * disabled or user TSconfig sets "options.contextReporter.enable = 0".
 * Stored reports are visible to administrators; reporters may download
 * their own reports.
 *
 * @internal
 */
final readonly class ReportAccessPolicy
{
    public function __construct(
        private ExtensionSettingsProvider $settingsProvider,
    ) {}

    public function canReport(mixed $backendUser): bool
    {
        if (!$backendUser instanceof BackendUserAuthentication || self::getUserId($backendUser) <= 0) {
            return false;
        }
        if (!$this->settingsProvider->get()->enabled) {
            return false;
        }
        $options = $backendUser->getTSConfig()['options.']['contextReporter.'] ?? [];
        return !is_array($options) || (bool)($options['enable'] ?? true);
    }

    public function canAccessReport(mixed $backendUser, Report $report): bool
    {
        if (!$backendUser instanceof BackendUserAuthentication || self::getUserId($backendUser) <= 0) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        return $report->reporterUid === self::getUserId($backendUser) && $this->canReport($backendUser);
    }

    public static function getUserId(BackendUserAuthentication $backendUser): int
    {
        return is_array($backendUser->user) ? (int)($backendUser->user['uid'] ?? 0) : 0;
    }
}
