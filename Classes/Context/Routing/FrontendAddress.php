<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Routing;

/**
 * The website address of a page in a language, as TYPO3 builds it from the
 * stored page and site configuration, with the facts that explain it.
 *
 * @internal
 */
final readonly class FrontendAddress
{
    /** The page belongs to no site */
    public const NO_SITE = 'noSite';
    /** The page is deleted in the reporter's workspace */
    public const DELETED_IN_WORKSPACE = 'deletedInWorkspace';
    /** The page only exists in the reporter's workspace */
    public const NEW_IN_WORKSPACE = 'newInWorkspace';
    /** TYPO3 offers no "View" for the page type (e.g. folders, spacers, TSconfig) */
    public const NOT_PREVIEWABLE = 'notPreviewable';
    /** The site has no such language */
    public const LANGUAGE_NOT_IN_SITE = 'languageNotInSite';
    /** The language is disabled in the site configuration */
    public const LANGUAGE_DISABLED = 'languageDisabled';
    /** The page is not translated into the language */
    public const PAGE_NOT_TRANSLATED = 'pageNotTranslated';
    /** The base of the site language has no host; the address is a path only */
    public const BASE_WITHOUT_HOST = 'baseWithoutHost';
    /** TYPO3 could not build the address */
    public const GENERATION_FAILED = 'generationFailed';

    /**
     * @param string $url Empty when no address can be given
     * @param list<string> $notes Facts in this order: see the constants
     * @param array{type: string, languages?: list<array{id: int, title?: string}>}|null $fallback Fallback configuration of a translation language
     */
    public function __construct(
        public string $url = '',
        public array $notes = [],
        public ?array $fallback = null,
    ) {}
}
