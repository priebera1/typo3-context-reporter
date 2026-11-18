<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\UpdateNotice;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Remembers, per backend user and per release, that the update notice was
 * dismissed. The list lives in the user's own configuration (`be_users.uc`),
 * so it follows the administrator across browsers and never hides the notice
 * from another administrator. Dismissing 0.5.0 hides only 0.5.0: a later
 * release is a different entry and shows again.
 *
 * @internal
 */
final readonly class UpdateNoticeDismissalStore
{
    public const UC_KEY = 'tx_contextreporter';
    public const UC_FIELD = 'dismissedUpdateVersions';
    private const MAX_REMEMBERED_VERSIONS = 10;

    public function isDismissed(BackendUserAuthentication $backendUser, SemanticVersion $version): bool
    {
        return in_array($version->toString(), $this->dismissedVersions($backendUser), true);
    }

    public function dismiss(BackendUserAuthentication $backendUser, SemanticVersion $version): void
    {
        $versions = array_values(array_filter(
            $this->dismissedVersions($backendUser),
            static fn(string $dismissed): bool => $dismissed !== $version->toString(),
        ));
        $versions[] = $version->toString();

        $configuration = $backendUser->uc;
        $extensionConfiguration = is_array($configuration[self::UC_KEY] ?? null) ? $configuration[self::UC_KEY] : [];
        $extensionConfiguration[self::UC_FIELD] = array_slice($versions, -self::MAX_REMEMBERED_VERSIONS);
        $configuration[self::UC_KEY] = $extensionConfiguration;

        $backendUser->uc = $configuration;
        $backendUser->writeUC();
    }

    /**
     * @return list<string>
     */
    private function dismissedVersions(BackendUserAuthentication $backendUser): array
    {
        $configuration = $backendUser->uc;
        $extensionConfiguration = is_array($configuration[self::UC_KEY] ?? null) ? $configuration[self::UC_KEY] : [];
        $stored = $extensionConfiguration[self::UC_FIELD] ?? [];
        if (!is_array($stored)) {
            return [];
        }
        $versions = [];
        foreach ($stored as $value) {
            $version = is_string($value) ? SemanticVersion::tryParse($value) : null;
            if ($version !== null) {
                $versions[] = $version->toString();
            }
        }
        return array_values(array_unique($versions));
    }
}
