<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * @internal
 */
#[AsTaggedItem(priority: 60)]
final readonly class SiteCollector implements ContextCollectorInterface
{
    public function __construct(
        private SiteFinder $siteFinder,
    ) {}

    public function getSectionKey(): string
    {
        return 'site';
    }

    public function collect(CollectionScope $scope): array
    {
        if ($scope->subject->pageUid <= 0) {
            return [];
        }
        try {
            $site = $this->siteFinder->getSiteByPageId($scope->subject->pageUid);
        } catch (SiteNotFoundException) {
            return [];
        }
        return [
            'identifier' => $site->getIdentifier(),
            'base' => (string)$site->getBase(),
            'rootPageId' => $site->getRootPageId(),
        ];
    }
}
