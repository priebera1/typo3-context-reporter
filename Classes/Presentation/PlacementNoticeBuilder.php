<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Presentation;

use Priebera\ContextReporter\Domain\ContextDocument;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Notices about the placement of content ("context.placement") in the
 * viewer's backend language: a column the backend layout does not have and
 * "Show content from page" in both directions. The column and the layout
 * themselves are part of the technical details only.
 *
 * @internal
 */
final readonly class PlacementNoticeBuilder
{
    use NoticeTrait;

    /**
     * @return list<string>
     */
    public function build(ContextDocument $document, LanguageService $languageService): array
    {
        $placement = $document->getContextSection('placement');
        if ($placement === []) {
            return [];
        }
        $notices = [];
        $column = $this->array($placement, 'column');
        if (($column['inBackendLayout'] ?? null) === false) {
            $columns = [];
            foreach ($this->list($placement, 'layoutColumns') as $layoutColumn) {
                $label = $this->string($layoutColumn, 'label');
                $columns[] = trim($label . ' [' . $this->string($layoutColumn, 'colPos') . ']');
            }
            $notices[] = sprintf($this->label('placement.columnNotInLayout', $languageService), $this->string($column, 'colPos'), implode(', ', $columns));
        }

        $pageTitle = $this->string($document->getContextSection('page'), 'title');
        $source = $this->array($placement, 'contentFromPage');
        if ($source !== []) {
            $notices[] = match (true) {
                ($source['missing'] ?? false) === true => sprintf($this->label('placement.contentFromMissingPage', $languageService), $pageTitle, $this->string($source, 'uid')),
                ($source['notAccessible'] ?? false) === true => sprintf($this->label('placement.contentFromInaccessiblePage', $languageService), $pageTitle, $this->string($source, 'uid')),
                default => sprintf($this->label('placement.contentFromPage', $languageService), $pageTitle, $this->string($source, 'title'), $this->string($source, 'uid')),
            };
        }

        $shownOn = $this->array($placement, 'contentShownOn');
        if ($shownOn !== []) {
            $pages = [];
            foreach ($this->list($shownOn, 'pages') as $page) {
                $pages[] = $this->string($page, 'title') . ' [' . $this->string($page, 'uid') . ']';
            }
            if ($this->int($shownOn, 'notListed') > 0) {
                $pages[] = sprintf($this->label('placement.morePages', $languageService), $this->int($shownOn, 'notListed'));
            }
            if ($this->int($shownOn, 'notAccessible') > 0) {
                $pages[] = sprintf($this->label('placement.inaccessiblePages', $languageService), $this->int($shownOn, 'notAccessible'));
            }
            $notices[] = sprintf($this->label('placement.contentShownOn', $languageService), $pageTitle, implode(', ', $pages));
        }
        return $notices;
    }
}
