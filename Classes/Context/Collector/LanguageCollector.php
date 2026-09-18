<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Priebera\ContextReporter\Context\Subject\SubjectLanguageResolver;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The content language the reporter was working in, resolved against the site
 * configuration.
 *
 * @internal
 */
#[AsTaggedItem(priority: 50)]
final readonly class LanguageCollector implements ContextCollectorInterface
{
    public function __construct(
        private SubjectLanguageResolver $languageResolver,
        private SiteFinder $siteFinder,
    ) {}

    public function getSectionKey(): string
    {
        return 'language';
    }

    public function collect(CollectionScope $scope): array
    {
        $languageId = $this->languageResolver->resolve($scope);
        if ($languageId === null) {
            return [];
        }
        if ($languageId === -1) {
            return ['id' => -1, 'title' => 'All languages'];
        }
        $data = ['id' => $languageId];
        if ($scope->subject->pageUid <= 0) {
            return $data;
        }
        try {
            $language = $this->siteFinder->getSiteByPageId($scope->subject->pageUid)->getLanguageById($languageId);
        } catch (\Throwable) {
            return $data;
        }
        $data['title'] = $language->getTitle();
        $data['locale'] = $language->getLocale()->getName();
        if (!$language->isEnabled()) {
            $data['enabled'] = false;
        }
        return $data;
    }
}
