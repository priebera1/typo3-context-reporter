<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Presentation;

use Priebera\ContextReporter\Context\Tca\TcaInspector;
use Priebera\ContextReporter\Context\Visibility\VisibilityEvaluator;
use Priebera\ContextReporter\Domain\ContextDocument;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Short notices about the stored visibility settings of a report
 * ("context.visibility") in the viewer's backend language, for the report
 * dialog and the report detail. Only settings that keep the object from
 * visitors are mentioned; the full data is in the technical details.
 *
 * @internal
 */
final readonly class VisibilityNoticeBuilder
{
    private const LABELS = 'LLL:EXT:context_reporter/Resources/Private/Language/locallang.xlf:';
    private const SEPARATOR = ' · ';

    private const WORKSPACE_LABELS = [
        VisibilityEvaluator::WORKSPACE_NEW => 'visibility.workspace.new',
        VisibilityEvaluator::WORKSPACE_CHANGED => 'visibility.workspace.changed',
        VisibilityEvaluator::WORKSPACE_DELETED => 'visibility.workspace.deleted',
    ];

    public function __construct(
        private TcaInspector $tca,
    ) {}

    /**
     * @return array{title: string, notices: list<string>, note: string}|null Null when there is nothing to point out
     */
    public function build(ContextDocument $document, LanguageService $languageService): ?array
    {
        $visibility = $document->getContextSection('visibility');
        if ($visibility === []) {
            return null;
        }

        $notices = $this->describe($this->array($visibility, 'subject'), $languageService);
        $page = $this->array($visibility, 'page');
        $pageFacts = $this->describe($page, $languageService);
        if ($pageFacts !== []) {
            $notices[] = sprintf($this->label('visibility.page', $languageService), $this->string($page, 'title'), implode(self::SEPARATOR, $pageFacts));
        }
        foreach ($this->list($this->array($visibility, 'parentPages'), 'restricting') as $parent) {
            $parentFacts = $this->describe($parent, $languageService);
            if ($parentFacts !== []) {
                $notices[] = sprintf($this->label('visibility.parentPage', $languageService), $this->string($parent, 'title'), implode(self::SEPARATOR, $parentFacts));
            }
        }
        array_push($notices, ...$this->describeTranslations($this->list($visibility, 'translations'), $languageService));

        if ($notices === []) {
            return null;
        }
        return [
            'title' => $this->label('visibility.title', $languageService),
            'notices' => $notices,
            'note' => $this->label('visibility.note', $languageService),
        ];
    }

    /**
     * @param list<array<array-key, mixed>> $translations
     * @return list<string>
     */
    private function describeTranslations(array $translations, LanguageService $languageService): array
    {
        $notices = [];
        $missing = ['page' => [], 'record' => []];
        $disabled = [];
        foreach ($translations as $translation) {
            $language = $this->string($translation, 'title') ?: $this->string($translation, 'languageId');
            if (($translation['enabled'] ?? true) === false) {
                $disabled[] = $language;
            }
            foreach (['page' => 'visibility.pageTranslation', 'record' => 'visibility.translation'] as $key => $labelKey) {
                $state = $this->array($translation, $key);
                if ($state === []) {
                    continue;
                }
                if (($state['exists'] ?? false) !== true) {
                    $missing[$key][] = $language;
                    continue;
                }
                $facts = $this->describe($state, $languageService);
                if ($facts !== []) {
                    $notices[] = sprintf($this->label($labelKey, $languageService), $language, implode(self::SEPARATOR, $facts));
                }
            }
        }
        if ($missing['page'] !== []) {
            $notices[] = sprintf($this->label('visibility.pageNotTranslated', $languageService), implode(', ', $missing['page']));
        }
        if ($missing['record'] !== []) {
            $notices[] = sprintf($this->label('visibility.notTranslated', $languageService), implode(', ', $missing['record']));
        }
        if ($disabled !== []) {
            $notices[] = sprintf($this->label('visibility.languageDisabled', $languageService), implode(', ', $disabled));
        }
        return $notices;
    }

    /**
     * @param array<array-key, mixed> $facts
     * @return list<string>
     */
    private function describe(array $facts, LanguageService $languageService): array
    {
        $reasons = array_filter(is_array($facts['reasons'] ?? null) ? $facts['reasons'] : [], is_string(...));
        $texts = [];
        if (in_array(VisibilityEvaluator::HIDDEN, $reasons, true)) {
            $texts[] = $this->label('visibility.hidden', $languageService);
        }
        if (in_array(VisibilityEvaluator::SCHEDULED, $reasons, true)) {
            $texts[] = sprintf($this->label('visibility.scheduled', $languageService), $this->formatTime($this->string($facts, 'starttime')));
        }
        if (in_array(VisibilityEvaluator::EXPIRED, $reasons, true)) {
            $texts[] = sprintf($this->label('visibility.expired', $languageService), $this->formatTime($this->string($facts, 'endtime')));
        }
        if (in_array(VisibilityEvaluator::ACCESS_RESTRICTED, $reasons, true)) {
            $texts[] = sprintf($this->label('visibility.accessRestricted', $languageService), $this->describeGroups($facts, $languageService));
        }
        if (($facts['hiddenInMenu'] ?? false) === true) {
            $texts[] = $this->label('visibility.hiddenInMenu', $languageService);
        }
        $workspaceLabel = self::WORKSPACE_LABELS[$this->string($facts, 'workspaceState')] ?? '';
        if ($workspaceLabel !== '') {
            $texts[] = $this->label($workspaceLabel, $languageService);
        }
        return $texts;
    }

    /**
     * @param array<array-key, mixed> $facts
     */
    private function describeGroups(array $facts, LanguageService $languageService): string
    {
        $titles = [];
        foreach ($this->list($facts, 'frontendGroups') as $group) {
            $id = (int)($group['id'] ?? 0);
            // "Hide at login" and "Show at any login" in the viewer's language
            $title = $id < 0 ? $this->tca->getItemLabel('pages', 'fe_group', (string)$id, $languageService) : '';
            $titles[] = $title ?: $this->string($group, 'title') ?: '#' . $id;
        }
        $notListed = (int)($facts['frontendGroupsNotListed'] ?? 0);
        if ($notListed > 0) {
            $titles[] = sprintf($this->label('visibility.moreGroups', $languageService), $notListed);
        }
        return implode(', ', $titles);
    }

    private function formatTime(string $time): string
    {
        try {
            $date = new \DateTimeImmutable($time);
        } catch (\Throwable) {
            return $time;
        }
        // The date and time format of the TYPO3 backend
        $dateFormat = $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] ?? '';
        $timeFormat = $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'] ?? '';
        return $date->format(
            (is_string($dateFormat) && $dateFormat !== '' ? $dateFormat : 'd-m-y') . ' ' . (is_string($timeFormat) && $timeFormat !== '' ? $timeFormat : 'H:i'),
        );
    }

    private function label(string $key, LanguageService $languageService): string
    {
        return $languageService->sL(self::LABELS . $key) ?: $key;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function array(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<array<array-key, mixed>>
     */
    private function list(array $data, string $key): array
    {
        return array_values(array_filter($this->array($data, $key), is_array(...)));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        return is_scalar($value) && !is_bool($value) ? (string)$value : '';
    }
}
