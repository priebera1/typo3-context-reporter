<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Presentation;

use Priebera\ContextReporter\Context\Tca\TcaInspector;
use Priebera\ContextReporter\Domain\ContextDocument;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Notices about where a reported file is used ("context.fileUsage") in the
 * viewer's backend language: the usages the report names and the counts
 * of the others.
 *
 * @internal
 */
final readonly class FileUsageNoticeBuilder
{
    use NoticeTrait;

    private const SEPARATOR = ' · ';

    public function __construct(
        private TcaInspector $tca,
    ) {}

    /**
     * @return list<string>
     */
    public function build(ContextDocument $document, LanguageService $languageService): array
    {
        $usage = $document->getContextSection('fileUsage');
        if ($usage === []) {
            return [];
        }
        if ($this->int($usage, 'references') === 0) {
            return [$this->label('fileUsage.none', $languageService)];
        }
        $notices = [];
        foreach ($this->list($usage, 'usages') as $entry) {
            $notices[] = sprintf($this->label('fileUsage.usage', $languageService), $this->describe($entry, $languageService));
        }
        foreach (['notListed', 'notAccessible', 'notChecked'] as $key) {
            if ($this->int($usage, $key) > 0) {
                $notices[] = sprintf($this->label('fileUsage.' . $key, $languageService), $this->int($usage, $key));
            }
        }
        return $notices;
    }

    /**
     * e.g. Page Content "Hero teaser" [tt_content:10] · Images · page "About" [2]
     *
     * @param array<array-key, mixed> $entry
     */
    private function describe(array $entry, LanguageService $languageService): string
    {
        $table = $this->string($entry, 'table');
        $field = $this->string($entry, 'field');
        $label = $this->string($entry, 'label');
        $parts = [
            trim(($this->tca->hasTable($table) ? $this->tca->getTableTitle($table, $languageService) : $table)
                . ($label !== '' ? ' "' . $label . '"' : '')
                . ' [' . $table . ':' . $this->string($entry, 'uid') . ']'),
            ($this->tca->hasColumn($table, $field) ? $this->tca->getColumnLabel($table, $field, $languageService) : '') ?: $this->string($entry, 'fieldLabel') ?: $field,
        ];
        $page = $this->array($entry, 'page');
        if ($page !== []) {
            $parts[] = sprintf($this->label('fileUsage.page', $languageService), $this->string($page, 'title'), $this->string($page, 'uid'));
        }
        if (($entry['hidden'] ?? false) === true) {
            $parts[] = $this->label('fileUsage.hidden', $languageService);
        }
        return implode(self::SEPARATOR, array_filter($parts, static fn(string $part): bool => $part !== ''));
    }
}
