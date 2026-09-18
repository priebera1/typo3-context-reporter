<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Facts about the reporting backend user, strictly limited to what the
 * privacy settings allow. Nothing is shared when all options are disabled.
 *
 * @internal
 */
#[AsTaggedItem(priority: 190)]
final readonly class ReporterCollector implements ContextCollectorInterface
{
    public function __construct(
        private ExtensionSettingsProvider $settingsProvider,
    ) {}

    public function getSectionKey(): string
    {
        return 'reporter';
    }

    public function collect(CollectionScope $scope): array
    {
        $privacy = $this->settingsProvider->get()->reporterPrivacy;
        $user = $scope->backendUser->user ?? [];
        $reporter = [];
        if ($privacy->includeUid) {
            $reporter['uid'] = (int)($user['uid'] ?? 0);
        }
        if ($privacy->includeUsername) {
            $reporter['username'] = (string)($user['username'] ?? '');
        }
        if ($privacy->includeRealName && (string)($user['realName'] ?? '') !== '') {
            $reporter['realName'] = (string)$user['realName'];
        }
        if ($privacy->includeEmail && (string)($user['email'] ?? '') !== '') {
            $reporter['email'] = (string)$user['email'];
        }
        if ($privacy->includeGroups) {
            $reporter['admin'] = $scope->backendUser->isAdmin();
            $groups = [];
            foreach ($scope->backendUser->userGroups as $group) {
                if (is_array($group) && isset($group['uid'])) {
                    $groups[] = ['uid' => (int)$group['uid'], 'title' => (string)($group['title'] ?? '')];
                }
            }
            usort($groups, static fn(array $a, array $b): int => $a['uid'] <=> $b['uid']);
            $reporter['groups'] = $groups;
        }
        return $reporter;
    }
}
