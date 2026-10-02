<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Presentation;

use Priebera\ContextReporter\Context\Routing\FrontendAddress;
use Priebera\ContextReporter\Context\Tca\TcaInspector;
use Priebera\ContextReporter\Domain\ContextDocument;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Notices about the website address of a report ("context.routing") in the
 * viewer's backend language: why there is no address, or which stored
 * settings explain it. The fallback languages are only mentioned for a page
 * that is not translated, where they matter.
 *
 * @internal
 */
final readonly class RoutingNoticeBuilder
{
    use NoticeTrait;

    private const SIMPLE_NOTES = [
        FrontendAddress::NO_SITE => 'website.noSite',
        FrontendAddress::DELETED_IN_WORKSPACE => 'website.deletedInWorkspace',
        FrontendAddress::NEW_IN_WORKSPACE => 'website.newInWorkspace',
        FrontendAddress::BASE_WITHOUT_HOST => 'website.baseWithoutHost',
        FrontendAddress::GENERATION_FAILED => 'website.generationFailed',
    ];

    public function __construct(
        private TcaInspector $tca,
    ) {}

    /**
     * @return list<string>
     */
    public function build(ContextDocument $document, LanguageService $languageService): array
    {
        $routing = $document->getContextSection('routing');
        $notes = array_values(array_filter($this->array($routing, 'notes'), is_string(...)));
        $language = $document->getContextSection('language');
        $languageName = $this->string($language, 'title') ?: $this->string($language, 'id');

        $notices = [];
        foreach ($notes as $note) {
            $notices = [...$notices, ...match ($note) {
                FrontendAddress::NOT_PREVIEWABLE => [sprintf($this->label('website.notPreviewable', $languageService), $this->getPageType($document, $languageService))],
                FrontendAddress::LANGUAGE_NOT_IN_SITE => [sprintf($this->label('website.languageNotInSite', $languageService), $this->string($language, 'id'))],
                FrontendAddress::LANGUAGE_DISABLED => [sprintf($this->label('website.languageDisabled', $languageService), $languageName)],
                FrontendAddress::PAGE_NOT_TRANSLATED => [
                    sprintf($this->label('website.pageNotTranslated', $languageService), $languageName),
                    $this->describeFallback($this->array($routing, 'fallback'), $languageName, $languageService),
                ],
                default => isset(self::SIMPLE_NOTES[$note]) ? [$this->label(self::SIMPLE_NOTES[$note], $languageService)] : [],
            }];
        }
        return array_values(array_filter($notices, static fn(string $notice): bool => $notice !== ''));
    }

    /**
     * @param array<array-key, mixed> $fallback
     */
    private function describeFallback(array $fallback, string $languageName, LanguageService $languageService): string
    {
        if ($fallback === []) {
            return '';
        }
        $names = [];
        foreach ($this->list($fallback, 'languages') as $language) {
            $names[] = $this->string($language, 'title') ?: 'ID ' . $this->string($language, 'id');
        }
        if ($names !== []) {
            return sprintf($this->label('website.fallback', $languageService), $languageName, implode(', ', $names));
        }
        return sprintf($this->label('website.noFallback', $languageService), $languageName, $this->string($fallback, 'type'));
    }

    private function getPageType(ContextDocument $document, LanguageService $languageService): string
    {
        $page = $document->getContextSection('page');
        $doktype = $this->string($page, 'doktype');
        return ($doktype !== '' ? $this->tca->getItemLabel('pages', 'doktype', $doktype, $languageService) : '')
            ?: $this->string($page, 'doktypeLabel') ?: $doktype;
    }
}
