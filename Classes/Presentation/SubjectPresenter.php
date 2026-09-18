<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Presentation;

use Priebera\ContextReporter\Context\Tca\TcaInspector;
use Priebera\ContextReporter\Domain\ContextDocument;
use Priebera\ContextReporter\Domain\SubjectType;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Turns the context document of a report into a subject presentation.
 *
 * Reports are stored in English. Type and module names are shown in the
 * backend language of the viewer where TYPO3 still knows them (TCA, module
 * registry); otherwise the stored English names are used.
 *
 * @internal
 */
final readonly class SubjectPresenter
{
    private const LABELS = 'LLL:EXT:context_reporter/Resources/Private/Language/locallang.xlf:';
    private const SEPARATOR = ' · ';
    private const MAX_LIST_TITLE_LENGTH = 60;

    public function __construct(
        private TcaInspector $tca,
        private ModuleProvider $moduleProvider,
        private IconFactory $iconFactory,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function present(ContextDocument $document): SubjectPresentation
    {
        $languageService = $this->getLanguageService();
        return match ($document->getSubjectType()) {
            SubjectType::Page => $this->presentPage($document, $languageService),
            SubjectType::Record => $this->presentRecord($document, $languageService),
            SubjectType::File => $this->presentFile($document, $languageService),
            SubjectType::Folder => $this->presentFolder($document, $languageService),
            SubjectType::Backend => $this->presentBackend($document, $languageService),
        };
    }

    private function presentPage(ContextDocument $document, LanguageService $languageService): SubjectPresentation
    {
        $subject = $document->getSubject();
        $page = $document->getContextSection('page');
        $uid = $this->string($page, 'uid') ?: $this->string($subject, 'uid');
        $doktype = $this->string($page, 'doktype');

        $typeLabel = $this->label('subject.kind.page', $languageService);
        if ($doktype !== '' && $doktype !== '1') {
            $doktypeLabel = $this->tca->getItemLabel('pages', 'doktype', $doktype, $languageService) ?: $this->string($page, 'doktypeLabel');
            $typeLabel .= $doktypeLabel !== '' ? self::SEPARATOR . $doktypeLabel : '';
        }

        return new SubjectPresentation(
            kind: SubjectType::Page->value,
            iconIdentifier: $this->recordIcon('pages', $doktype !== '' ? ['doktype' => $doktype] : [], 'apps-pagetree-page-default'),
            typeLabel: $typeLabel,
            title: $this->string($page, 'title') ?: $this->string($subject, 'label') ?: $this->label('subject.untitled', $languageService),
            identifier: $uid !== '' ? sprintf($this->label('subject.uid', $languageService), $uid) : '',
            facts: $this->contextFacts($document, $languageService),
            backendUrl: $this->string($page, 'backendUrl') ?: $this->string($subject, 'backendUrl'),
            frontendUrl: $this->string($page, 'frontendUrl'),
            listTitle: $this->shorten($this->string($page, 'title') ?: $this->string($subject, 'label')),
        );
    }

    private function presentRecord(ContextDocument $document, LanguageService $languageService): SubjectPresentation
    {
        $subject = $document->getSubject();
        $record = $document->getContextSection('record');
        $table = $this->string($record, 'table') ?: $this->string($subject, 'table');
        $type = is_array($record['type'] ?? null) ? $record['type'] : [];
        $typeField = $this->string($type, 'field');
        $typeValue = $this->string($type, 'value');

        $tableTitle = $this->tca->hasTable($table)
            ? $this->tca->getTableTitle($table, $languageService)
            : ($this->string($record, 'tableTitle') ?: $table);
        $recordType = '';
        if ($typeField !== '' && $typeValue !== '') {
            $recordType = $this->tca->getItemLabel($table, $typeField, $typeValue, $languageService) ?: $this->string($type, 'label');
        }
        $isNew = ($record['isNew'] ?? $subject['isNew'] ?? false) === true;
        $uid = $this->string($record, 'uid') ?: $this->string($subject, 'uid');
        $label = $this->string($record, 'label') ?: $this->string($subject, 'label');
        if (preg_match('/[\r\n]/', $label)) {
            // Stored by an earlier version from free text such as the body text of a content element
            $label = '';
        }

        if ($isNew) {
            $title = $this->label('subject.newRecord', $languageService);
            $typeLabel = $tableTitle;
            $identifier = $table;
        } elseif ($label !== '') {
            $title = $label;
            $typeLabel = $tableTitle . ($recordType !== '' ? self::SEPARATOR . $recordType : '');
            $identifier = $table . ':' . $uid;
        } else {
            // Without a title, the record type is the best name, e.g. "Plain HTML"
            $title = $recordType !== '' ? $recordType : $this->label('subject.untitled', $languageService);
            $typeLabel = $tableTitle;
            $identifier = $table . ':' . $uid;
        }

        $page = $document->getContextSection('page');
        $pageFact = [];
        if ($page !== []) {
            $pageFact[] = $this->fact('page', 'subject.fact.page', sprintf('%s [%s]', $this->string($page, 'title'), $this->string($page, 'uid')), $languageService);
        }

        return new SubjectPresentation(
            kind: SubjectType::Record->value,
            iconIdentifier: $this->recordIcon($table, $typeField !== '' ? [$typeField => $typeValue] : [], 'mimetypes-other-other'),
            typeLabel: $typeLabel,
            title: $title,
            identifier: $identifier,
            location: $this->string($page, 'title'),
            facts: array_merge($pageFact, $this->contextFacts($document, $languageService)),
            backendUrl: $this->string($record, 'backendUrl') ?: $this->string($subject, 'backendUrl'),
            frontendUrl: $this->string($page, 'frontendUrl'),
            listTitle: $this->shorten($title, $recordType),
        );
    }

    private function presentFile(ContextDocument $document, LanguageService $languageService): SubjectPresentation
    {
        $subject = $document->getSubject();
        $file = $document->getContextSection('file');
        $folder = $document->getContextSection('folder');
        $storage = $this->string($document->getContextSection('storage'), 'name');
        $folderIdentifier = $this->string($folder, 'identifier');
        $mimeType = $this->string($file, 'mimeType');

        $facts = [];
        if ($storage !== '') {
            $facts[] = $this->fact('storage', 'subject.fact.storage', $storage, $languageService);
        }
        if ($folderIdentifier !== '') {
            $facts[] = $this->fact('folder', 'subject.fact.folder', $folderIdentifier, $languageService);
        }

        return new SubjectPresentation(
            kind: SubjectType::File->value,
            iconIdentifier: $this->fileIcon($this->string($file, 'extension')),
            typeLabel: $this->label('subject.kind.file', $languageService) . ($mimeType !== '' ? self::SEPARATOR . $mimeType : ''),
            title: $this->string($file, 'name') ?: $this->string($subject, 'label'),
            identifier: 'sys_file:' . ($this->string($file, 'uid') ?: $this->string($subject, 'uid')),
            location: implode(': ', array_filter([$storage, $folderIdentifier])),
            facts: array_merge($facts, $this->contextFacts($document, $languageService)),
            backendUrl: $this->string($file, 'backendUrl') ?: $this->string($subject, 'backendUrl'),
            listTitle: $this->shorten($this->string($file, 'name') ?: $this->string($subject, 'label')),
        );
    }

    private function presentFolder(ContextDocument $document, LanguageService $languageService): SubjectPresentation
    {
        $subject = $document->getSubject();
        $folder = $document->getContextSection('folder');
        $storage = $this->string($document->getContextSection('storage'), 'name');

        $facts = [];
        if ($storage !== '') {
            $facts[] = $this->fact('storage', 'subject.fact.storage', $storage, $languageService);
        }

        return new SubjectPresentation(
            kind: SubjectType::Folder->value,
            iconIdentifier: 'apps-filetree-folder-default',
            typeLabel: $this->label('subject.kind.folder', $languageService),
            title: $this->string($folder, 'name') ?: $this->string($subject, 'label'),
            identifier: $this->string($subject, 'identifier'),
            location: $storage,
            facts: array_merge($facts, $this->contextFacts($document, $languageService)),
            backendUrl: $this->string($folder, 'backendUrl') ?: $this->string($subject, 'backendUrl'),
            listTitle: $this->shorten($this->string($folder, 'name') ?: $this->string($subject, 'label')),
        );
    }

    private function presentBackend(ContextDocument $document, LanguageService $languageService): SubjectPresentation
    {
        $subject = $document->getSubject();
        $backend = $document->getContextSection('backend');
        $module = is_array($backend['module'] ?? null) ? $backend['module'] : [];
        $moduleIdentifier = $this->string($module, 'identifier');
        $route = is_array($backend['route'] ?? null) ? $this->string($backend['route'], 'identifier') : '';

        if ($moduleIdentifier !== '') {
            return new SubjectPresentation(
                kind: SubjectType::Backend->value,
                iconIdentifier: $this->moduleProvider->getModule($moduleIdentifier)?->getIconIdentifier() ?: 'actions-window',
                typeLabel: $this->label('subject.kind.module', $languageService),
                title: $this->moduleTitle($module, $languageService),
                identifier: $moduleIdentifier,
                facts: $this->contextFacts($document, $languageService, false),
                backendUrl: $this->string($subject, 'backendUrl'),
            );
        }
        return new SubjectPresentation(
            kind: SubjectType::Backend->value,
            iconIdentifier: 'actions-window',
            typeLabel: $this->label('subject.kind.backend', $languageService),
            title: $this->string($subject, 'label') ?: ($route !== '' ? $route : 'TYPO3'),
            identifier: $route !== '' && $route !== $this->string($subject, 'label') ? $route : '',
            facts: $this->contextFacts($document, $languageService, false),
        );
    }

    /**
     * Site, language, workspace and (unless the module is the subject) the backend module.
     *
     * @return list<array{key: string, label: string, value: string}>
     */
    private function contextFacts(ContextDocument $document, LanguageService $languageService, bool $withModule = true): array
    {
        $facts = [];
        $site = $this->string($document->getContextSection('site'), 'identifier');
        if ($site !== '') {
            $facts[] = $this->fact('site', 'subject.fact.site', $site, $languageService);
        }
        $language = $document->getContextSection('language');
        if ($language !== []) {
            $facts[] = $this->fact('language', 'subject.fact.language', $this->string($language, 'title') ?: $this->string($language, 'id'), $languageService);
        }
        $workspace = $this->string($document->getContextSection('workspace'), 'title');
        if ($workspace !== '') {
            $facts[] = $this->fact('workspace', 'subject.fact.workspace', $workspace, $languageService);
        }
        $module = $document->getContextSection('backend')['module'] ?? null;
        if ($withModule && is_array($module) && $this->string($module, 'identifier') !== '') {
            $facts[] = $this->fact('module', 'subject.fact.module', $this->moduleTitle($module, $languageService), $languageService);
        }
        return $facts;
    }

    /**
     * @param array<array-key, mixed> $module Module data of the report
     */
    private function moduleTitle(array $module, LanguageService $languageService): string
    {
        $registered = $this->moduleProvider->getModule($this->string($module, 'identifier'));
        if ($registered !== null) {
            $parts = [
                $registered->getParentModule() !== null ? $languageService->sL($registered->getParentModule()->getTitle()) : '',
                $languageService->sL($registered->getTitle()),
            ];
        } else {
            $parts = [$this->string($module, 'group'), $this->string($module, 'title')];
        }
        return implode(' › ', array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    /**
     * Long titles would dominate the report list: use the fallback (the record
     * type) or cut the title.
     */
    private function shorten(string $title, string $fallback = ''): string
    {
        if (mb_strlen($title) <= self::MAX_LIST_TITLE_LENGTH) {
            return $title;
        }
        return $fallback !== '' ? $fallback : mb_substr($title, 0, self::MAX_LIST_TITLE_LENGTH - 1) . '…';
    }

    /**
     * @param array<string, string> $row The record type only, never field values
     */
    private function recordIcon(string $table, array $row, string $fallback): string
    {
        if (!$this->tca->hasTable($table)) {
            return $fallback;
        }
        try {
            return $this->iconFactory->getIconForRecord($table, $row, IconSize::SMALL)->getIdentifier();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function fileIcon(string $extension): string
    {
        try {
            return $this->iconFactory->getIconForFileExtension($extension !== '' ? $extension : 'default', IconSize::SMALL)->getIdentifier();
        } catch (\Throwable) {
            return 'mimetypes-other-other';
        }
    }

    /**
     * @return array{key: string, label: string, value: string}
     */
    private function fact(string $key, string $labelKey, string $value, LanguageService $languageService): array
    {
        return ['key' => $key, 'label' => $this->label($labelKey, $languageService), 'value' => $value];
    }

    private function label(string $key, LanguageService $languageService): string
    {
        return $languageService->sL(self::LABELS . $key) ?: $key;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        return is_scalar($value) && !is_bool($value) ? (string)$value : '';
    }

    private function getLanguageService(): LanguageService
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        return $languageService instanceof LanguageService ? $languageService : $this->languageServiceFactory->create('en');
    }
}
