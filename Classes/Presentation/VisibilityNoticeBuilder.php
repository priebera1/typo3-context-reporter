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
 * Times are shown in the server time, like everywhere in the TYPO3 backend;
 * when the reporter's browser was in another time zone, the server time
 * zone is named.
 *
 * @internal
 */
final readonly class VisibilityNoticeBuilder
{
    use NoticeTrait;

    private const SEPARATOR = ' · ';

    private const WORKSPACE_LABELS = [
        VisibilityEvaluator::WORKSPACE_NEW => 'visibility.workspace.new',
        VisibilityEvaluator::WORKSPACE_CHANGED => 'visibility.workspace.changed',
        VisibilityEvaluator::WORKSPACE_DELETED => 'visibility.workspace.deleted',
    ];

    private const TRANSLATION_BEHAVIOUR_LABELS = [
        'hideDefaultLanguage' => 'visibility.hideDefaultLanguage',
        'hideIfNotTranslated' => 'visibility.hideIfNotTranslated',
    ];

    public function __construct(
        private TcaInspector $tca,
    ) {}

    /**
     * @return list<string>
     */
    public function build(ContextDocument $document, LanguageService $languageService): array
    {
        $visibility = $document->getContextSection('visibility');
        if ($visibility === []) {
            return [];
        }
        $serverTimeZone = $this->getServerTimeZoneToName($document);

        $notices = $this->describe($this->array($visibility, 'subject'), $languageService, $serverTimeZone);
        $page = $this->array($visibility, 'page');
        $pageFacts = $this->describe($page, $languageService, $serverTimeZone);
        if ($pageFacts !== []) {
            $notices[] = sprintf($this->label('visibility.page', $languageService), $this->string($page, 'title'), implode(self::SEPARATOR, $pageFacts));
        }
        foreach ($this->list($this->array($visibility, 'parentPages'), 'restricting') as $parent) {
            $parentFacts = $this->describe($parent, $languageService, $serverTimeZone);
            if ($parentFacts !== []) {
                $notices[] = sprintf($this->label('visibility.parentPage', $languageService), $this->string($parent, 'title'), implode(self::SEPARATOR, $parentFacts));
            }
        }
        return [...$notices, ...$this->describeTranslations($this->list($visibility, 'translations'), $languageService, $serverTimeZone)];
    }

    /**
     * @param list<array<array-key, mixed>> $translations
     * @return list<string>
     */
    private function describeTranslations(array $translations, LanguageService $languageService, string $serverTimeZone): array
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
                $facts = $this->describe($state, $languageService, $serverTimeZone);
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
    private function describe(array $facts, LanguageService $languageService, string $serverTimeZone): array
    {
        $reasons = array_filter(is_array($facts['reasons'] ?? null) ? $facts['reasons'] : [], is_string(...));
        $texts = [];
        if (in_array(VisibilityEvaluator::HIDDEN, $reasons, true)) {
            $texts[] = $this->label('visibility.hidden', $languageService);
        }
        if (in_array(VisibilityEvaluator::SCHEDULED, $reasons, true)) {
            $texts[] = sprintf($this->label('visibility.scheduled', $languageService), $this->formatTime($this->string($facts, 'starttime'), $serverTimeZone, $languageService));
        }
        if (in_array(VisibilityEvaluator::EXPIRED, $reasons, true)) {
            $texts[] = sprintf($this->label('visibility.expired', $languageService), $this->formatTime($this->string($facts, 'endtime'), $serverTimeZone, $languageService));
        }
        if (in_array(VisibilityEvaluator::ACCESS_RESTRICTED, $reasons, true)) {
            $texts[] = sprintf($this->label('visibility.accessRestricted', $languageService), $this->describeGroups($facts, $languageService));
        }
        if (($facts['hiddenInMenu'] ?? false) === true) {
            $texts[] = $this->label('visibility.hiddenInMenu', $languageService);
        }
        foreach (self::TRANSLATION_BEHAVIOUR_LABELS as $key => $labelKey) {
            if (($this->array($facts, 'translationBehaviour')[$key] ?? false) === true) {
                $texts[] = $this->label($labelKey, $languageService);
            }
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

    /**
     * The server time zone when the reporter's browser reported another one,
     * otherwise empty.
     */
    private function getServerTimeZoneToName(ContextDocument $document): string
    {
        $serverTimeZone = $this->string($document->getSection('system'), 'timeZone');
        $browserTimeZone = $this->string($document->getSection('browser'), 'timeZone');
        return $serverTimeZone !== '' && $browserTimeZone !== '' && $serverTimeZone !== $browserTimeZone ? $serverTimeZone : '';
    }

    private function formatTime(string $time, string $serverTimeZone, LanguageService $languageService): string
    {
        try {
            $date = new \DateTimeImmutable($time);
        } catch (\Throwable) {
            return $time;
        }
        // The date and time format of the TYPO3 backend
        $dateFormat = $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] ?? '';
        $timeFormat = $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'] ?? '';
        $formatted = $date->format(
            (is_string($dateFormat) && $dateFormat !== '' ? $dateFormat : 'd-m-y') . ' ' . (is_string($timeFormat) && $timeFormat !== '' ? $timeFormat : 'H:i'),
        );
        return $serverTimeZone !== '' ? sprintf($this->label('visibility.serverTime', $languageService), $formatted, $serverTimeZone) : $formatted;
    }
}
