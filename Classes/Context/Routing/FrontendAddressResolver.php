<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Routing;

use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\Subject\RecordAccess;
use Priebera\ContextReporter\Context\Subject\SubjectLanguageResolver;
use Priebera\ContextReporter\Context\Tca\TcaInspector;
use Priebera\ContextReporter\Context\Visibility\VisibilityEvaluator;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The website address of the page a report is about (or of the page of a
 * reported record) in the language of the report, and the stored facts that
 * explain it: site, language, translation, page type and workspace state.
 *
 * TYPO3's router builds an address from the page and the site configuration
 * without checking whether visitors can open it: disabled languages, missing
 * translations and pages that only exist in a workspace get an address, and a
 * page deleted in the workspace would get the address of the home page. The
 * address is therefore only given when it belongs to the page, and the facts
 * are reported alongside; whether the website shows the page is not decided
 * here. Translations are only looked up in languages the reporter may use.
 *
 * @internal
 */
final readonly class FrontendAddressResolver
{
    /** @var \WeakMap<CollectionScope, FrontendAddress|null> */
    private \WeakMap $resolved;

    public function __construct(
        private SiteFinder $siteFinder,
        private SubjectLanguageResolver $languageResolver,
        private RecordAccess $recordAccess,
        private PagePreviewability $previewability,
        private VisibilityEvaluator $visibilityEvaluator,
        private TcaInspector $tca,
        private Context $context,
    ) {
        $this->resolved = new \WeakMap();
    }

    /**
     * @return FrontendAddress|null Null when the report is not about a page or a record on a page
     */
    public function resolve(CollectionScope $scope): ?FrontendAddress
    {
        if (!$this->resolved->offsetExists($scope)) {
            $this->resolved[$scope] = $scope->subject->hasPage() ? $this->build($scope) : null;
        }
        return $this->resolved[$scope];
    }

    private function build(CollectionScope $scope): FrontendAddress
    {
        $page = $scope->subject->page;
        $pageUid = $scope->subject->pageUid;
        $workspace = (int)$this->context->getPropertyFromAspect('workspace', 'id', 0);
        $workspaceState = $this->visibilityEvaluator->getWorkspaceState($page, $workspace);
        if ($workspaceState === VisibilityEvaluator::WORKSPACE_DELETED) {
            // TYPO3 would link the home page
            return new FrontendAddress(notes: [FrontendAddress::DELETED_IN_WORKSPACE]);
        }
        $notes = $workspaceState === VisibilityEvaluator::WORKSPACE_NEW ? [FrontendAddress::NEW_IN_WORKSPACE] : [];

        try {
            $site = $this->siteFinder->getSiteByPageId($pageUid);
        } catch (SiteNotFoundException) {
            return new FrontendAddress(notes: [FrontendAddress::NO_SITE, ...$notes]);
        }
        if (!$this->previewability->isPreviewable($page)) {
            return new FrontendAddress(notes: [...$notes, FrontendAddress::NOT_PREVIEWABLE]);
        }

        $languageId = max(0, $this->languageResolver->resolve($scope) ?? 0);
        try {
            $language = $site->getLanguageById($languageId);
        } catch (\InvalidArgumentException) {
            return new FrontendAddress(notes: [...$notes, FrontendAddress::LANGUAGE_NOT_IN_SITE]);
        }
        if (!$language->isEnabled()) {
            $notes[] = FrontendAddress::LANGUAGE_DISABLED;
        }
        $fallback = null;
        if ($languageId > 0) {
            if ($scope->backendUser->checkLanguageAccess($languageId) && !$this->isTranslated($page, $languageId, $scope)) {
                $notes[] = FrontendAddress::PAGE_NOT_TRANSLATED;
            }
            $fallback = $this->describeFallback($language, $site, $scope);
        }
        if ($language->getBase()->getHost() === '') {
            $notes[] = FrontendAddress::BASE_WITHOUT_HOST;
        }

        try {
            $url = (string)$site->getRouter()->generateUri($pageUid, ['_language' => $languageId]);
        } catch (\Throwable) {
            $url = '';
            $notes[] = FrontendAddress::GENERATION_FAILED;
        }
        return new FrontendAddress($url, $notes, $fallback);
    }

    /**
     * @param array<string, mixed> $page The page of the report: its default language row or a translation
     */
    private function isTranslated(array $page, int $languageId, CollectionScope $scope): bool
    {
        $languageField = $this->tca->getLanguageField('pages');
        if ($languageField !== '' && (int)($page[$languageField] ?? 0) === $languageId) {
            return true;
        }
        return $this->recordAccess->hasPageTranslation((int)($page['uid'] ?? 0), $languageId, $scope->backendUser);
    }

    /**
     * The fallback chain of the language, named where the reporter may use the language.
     *
     * @return array{type: string, languages?: list<array{id: int, title?: string}>}
     */
    private function describeFallback(SiteLanguage $language, Site $site, CollectionScope $scope): array
    {
        $languages = [];
        foreach ($language->getFallbackLanguageIds() as $fallbackId) {
            $entry = ['id' => $fallbackId];
            if ($scope->backendUser->checkLanguageAccess($fallbackId)) {
                try {
                    $entry['title'] = $site->getLanguageById($fallbackId)->getTitle();
                } catch (\InvalidArgumentException) {
                    // A fallback to a language the site does not have
                }
            }
            $languages[] = $entry;
        }
        $fallback = ['type' => $language->getFallbackType()];
        if ($languages !== []) {
            $fallback['languages'] = $languages;
        }
        return $fallback;
    }
}
