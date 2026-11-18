<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\UpdateNotice;

use Priebera\ContextReporter\Configuration\ExtensionInfo;
use Priebera\ContextReporter\Security\ReportAccessPolicy;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The "new Context Reporter version available" notice of System › Context
 * Reports.
 *
 * Only administrators see it: they are the users who can update an
 * extension. For everybody else (including editors granted the module) no
 * release check runs at all. The notice appears when the release endpoint
 * reports a stable release newer than the installed one and this
 * administrator has not dismissed that release. Every failure (unknown or
 * development installed version, unreachable endpoint, malformed answer,
 * broken cache) ends in "no notice", never in an error on the module page.
 *
 * @internal
 */
final readonly class UpdateNoticeService
{
    public function __construct(
        private LatestReleaseProvider $latestReleaseProvider,
        private UpdateNoticeDismissalStore $dismissalStore,
        private ExtensionInfo $extensionInfo,
    ) {}

    public function canSeeUpdateNotices(mixed $backendUser): bool
    {
        return $backendUser instanceof BackendUserAuthentication
            && ReportAccessPolicy::getUserId($backendUser) > 0
            && $backendUser->isAdmin();
    }

    /**
     * @return array{version: string, installedVersion: string, releasedAt: string, releaseUrl: string}|null
     */
    public function buildFor(mixed $backendUser): ?array
    {
        try {
            if (!$backendUser instanceof BackendUserAuthentication || !$this->canSeeUpdateNotices($backendUser)) {
                return null;
            }
            $installed = SemanticVersion::tryParse($this->extensionInfo->getVersion());
            if ($installed === null) {
                return null;
            }
            $latest = $this->latestReleaseProvider->getLatestRelease();
            if ($latest === null
                || !$latest->version->isNewerThan($installed)
                || $this->dismissalStore->isDismissed($backendUser, $latest->version)
            ) {
                return null;
            }
            return [
                'version' => $latest->version->toString(),
                'installedVersion' => $installed->toString(),
                'releasedAt' => $latest->releasedAt,
                'releaseUrl' => $latest->releaseUrl,
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
