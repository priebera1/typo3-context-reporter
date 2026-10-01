<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Export;

/**
 * Turns a report payload into human readable, ordered sections of
 * "label: value" rows. Used for the email {context.details} marker, the
 * Markdown export and the report history module.
 *
 * Labels are English on purpose: reports are read by developers and support
 * teams, independent of the reporter's backend language.
 *
 * @internal
 */
final class ContextDetailsFormatter
{
    private const CONTEXT_SECTIONS = [
        'page' => 'Page',
        'record' => 'Record',
        'file' => 'File',
        'folder' => 'Folder',
        'storage' => 'Storage',
        'formEngine' => 'Editing form',
        'site' => 'Site',
        'language' => 'Language',
        'workspace' => 'Workspace',
        'visibility' => 'Visibility',
        'fileChecks' => 'File checks',
        'backend' => 'Backend',
        'recentErrors' => 'Recent backend errors',
    ];

    public const VISIBILITY_NOTE = 'Stored TYPO3 settings. Templates, caches, extensions and other frontend logic can still change what the website shows.';

    public const FILE_CHECKS_NOTE = 'Based on the TYPO3 file index. Only a reported file is also looked up in its storage.';

    private const FILE_PROBLEMS = [
        'hidden' => 'reference hidden',
        'brokenReference' => 'referenced file no longer exists',
        'missing' => 'marked as missing',
        'storageOffline' => 'storage offline in the backend',
        'empty' => 'empty (0 bytes)',
    ];

    private const STORAGE_CHECKS = [
        'found' => 'found in its storage',
        'notFound' => 'not found in its storage',
        'notChecked' => 'not looked up in its storage',
    ];

    private const LABELS = [
        'uid' => 'UID',
        'pid' => 'PID',
        'id' => 'ID',
        'url' => 'URL',
        'backendUrl' => 'Backend link',
        'frontendUrl' => 'Frontend URL',
        'editUrl' => 'Edit link',
        'isNew' => 'New record',
        'parameters' => 'Parameters',
        'windowMinutes' => 'Time window (minutes)',
        'storageUid' => 'Storage UID',
        'mimeType' => 'MIME type',
        'metadataUid' => 'Metadata UID',
        'editMetadataUrl' => 'Edit metadata link',
        'typeLabel' => 'Type label',
        'tableTitle' => 'Table title',
        'doktype' => 'Page type ID',
        'doktypeLabel' => 'Page type',
        'languageId' => 'Language ID',
        'colPos' => 'Column (colPos)',
        'rootPageId' => 'Root page ID',
        'columnsOnly' => 'Restricted to fields',
        'newRecord' => 'New record',
        'workspaceVersionUid' => 'Workspace version UID',
        'translationSourceUid' => 'Translation source UID',
        'backendLanguage' => 'Backend language',
        'userSwitchActive' => 'Switched user session',
        'realName' => 'Name',
        'admin' => 'Administrator',
        'typo3Version' => 'TYPO3 version',
        'phpVersion' => 'PHP version',
        'applicationContext' => 'Application context',
        'composerMode' => 'Composer mode',
        'databasePlatform' => 'Database',
        'operatingSystem' => 'Operating system',
        'extensionVersion' => 'Context Reporter version',
        'os' => 'Operating system',
        'userAgent' => 'User agent',
        'timeZone' => 'Time zone',
        'devicePixelRatio' => 'Pixel ratio',
        'colorScheme' => 'Preferred color scheme',
        'backendColorScheme' => 'Backend color scheme',
        'reducedMotion' => 'Reduced motion',
    ];

    /**
     * @param array<string, mixed> $payload
     * @return list<array{title: string, rows: array<string, string>}>
     */
    public function buildSections(array $payload): array
    {
        $sections = [];
        $this->addSection($sections, 'Subject', $payload['subject'] ?? null);

        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        foreach (self::CONTEXT_SECTIONS as $key => $title) {
            if ($key === 'visibility' && is_array($context[$key] ?? null)) {
                $rows = $this->buildVisibilityRows($context[$key]);
                if ($rows !== []) {
                    $sections[] = ['title' => $title, 'rows' => $rows];
                }
            } elseif ($key === 'fileChecks' && is_array($context[$key] ?? null)) {
                $rows = $this->buildFileCheckRows($context[$key]);
                if ($rows !== []) {
                    $sections[] = ['title' => $title, 'rows' => $rows];
                }
            } elseif (array_key_exists($key, $context)) {
                $this->addSection($sections, $title, $context[$key]);
            }
        }
        foreach ($context as $key => $data) {
            if (!array_key_exists((string)$key, self::CONTEXT_SECTIONS)) {
                $this->addSection($sections, $this->humanize((string)$key), $data);
            }
        }

        $this->addSection($sections, 'Reporter', $payload['reporter'] ?? null);
        $this->addSection($sections, 'Project', $payload['project'] ?? null);
        $this->addSection($sections, 'System', $payload['system'] ?? null);
        $this->addSection($sections, 'Browser', $payload['browser'] ?? null);
        return $sections;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function toText(array $payload): string
    {
        $blocks = [];
        foreach ($this->buildSections($payload) as $section) {
            $lines = [$section['title']];
            foreach ($section['rows'] as $label => $value) {
                $lines[] = '  ' . $label . ': ' . $value;
            }
            $blocks[] = implode("\n", $lines);
        }
        return implode("\n\n", $blocks);
    }

    public function formatValue(string $key, mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if ($key === 'size' && is_int($value)) {
            return $value . ' bytes';
        }
        if (is_float($value)) {
            return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        if (!is_array($value) || $value === []) {
            return '';
        }

        if (isset($value['width'], $value['height']) && count($value) === 2) {
            return $value['width'] . '×' . $value['height'];
        }
        switch ($key) {
            case 'rootline':
                return $this->joinItems($value, ' › ', static fn(array $item): string => ($item['title'] ?? '') . ' [' . ($item['uid'] ?? '') . ']');
            case 'groups':
                return $this->joinItems($value, ', ', static fn(array $item): string => ($item['title'] ?? '') . ' [' . ($item['uid'] ?? '') . ']');
            case 'records':
                return $this->joinItems($value, ', ', static fn(array $item): string => ($item['table'] ?? '') . ':' . ($item['uid'] ?? ''));
            case 'type':
                if (isset($value['value'])) {
                    $label = (string)($value['label'] ?? '');
                    $technical = ($value['field'] ?? '') !== '' ? $value['field'] . ' = ' . $value['value'] : (string)$value['value'];
                    return $label !== '' ? $label . ' (' . $technical . ')' : $technical;
                }
                break;
            case 'module':
                if (isset($value['identifier'])) {
                    $title = implode(' › ', array_filter([(string)($value['group'] ?? ''), (string)($value['title'] ?? '')]));
                    return $title !== '' ? $title . ' (' . $value['identifier'] . ')' : (string)$value['identifier'];
                }
                break;
            case 'route':
                if (isset($value['identifier'])) {
                    return $value['identifier'] . (isset($value['path']) ? ' (' . $value['path'] . ')' : '');
                }
                break;
            case 'columnsOnly':
                $parts = [];
                foreach ($value as $table => $fields) {
                    $parts[] = $table . ': ' . (is_array($fields) ? implode(', ', array_map(strval(...), array_filter($fields, is_scalar(...)))) : '');
                }
                return implode('; ', $parts);
        }

        if (array_is_list($value)) {
            $items = [];
            foreach ($value as $item) {
                $items[] = is_array($item) ? $this->formatAssociative($item, ', ') : $this->formatValue('', $item);
            }
            return implode(array_filter($value, is_array(...)) !== [] ? ' | ' : ', ', array_filter($items, static fn(string $item): bool => $item !== ''));
        }
        return $this->formatAssociative($value, '; ');
    }

    /**
     * The visibility settings as readable rows: the reported object, its page,
     * parent pages that pass restrictions on, and translations.
     *
     * @param array<array-key, mixed> $visibility
     * @return array<string, string>
     */
    private function buildVisibilityRows(array $visibility): array
    {
        $rows = [];
        if (is_string($visibility['evaluatedAt'] ?? null)) {
            $rows['Evaluated at'] = $visibility['evaluatedAt'];
        }
        if (is_array($visibility['subject'] ?? null)) {
            $rows['Reported object'] = $this->describeVisibility($visibility['subject']);
        }
        $page = $visibility['page'] ?? null;
        if (is_array($page)) {
            $rows['Page'] = sprintf('"%s" [%s]: %s', $page['title'] ?? '', $page['uid'] ?? '', $this->describeVisibility($page));
        }
        $parents = $visibility['parentPages'] ?? null;
        if (is_array($parents)) {
            $items = [];
            foreach (is_array($parents['restricting'] ?? null) ? $parents['restricting'] : [] as $parent) {
                if (is_array($parent)) {
                    $items[] = sprintf('"%s" [%s]: %s', $parent['title'] ?? '', $parent['uid'] ?? '', $this->describeVisibility($parent));
                }
            }
            $value = $items !== [] ? implode('; ', $items) : 'none';
            if (($parents['checkedUpToRoot'] ?? true) === false) {
                $value .= ' (parent pages without access were not checked)';
            }
            $rows['Parent pages with "Extend to subpages"'] = $value;
        }
        foreach (is_array($visibility['translations'] ?? null) ? $visibility['translations'] : [] as $translation) {
            if (!is_array($translation)) {
                continue;
            }
            $parts = [];
            if (($translation['enabled'] ?? true) === false) {
                $parts[] = 'language disabled';
            }
            foreach (['page' => 'page', 'record' => 'record'] as $key => $name) {
                if (is_array($translation[$key] ?? null)) {
                    $parts[] = $name . ': ' . $this->describeTranslation($translation[$key]);
                }
            }
            $rows[sprintf('Translation %s [%s]', $translation['title'] ?? '', $translation['languageId'] ?? '')] = implode('; ', $parts);
        }
        if ($rows !== []) {
            $rows['Note'] = self::VISIBILITY_NOTE;
        }
        return $rows;
    }

    /**
     * @param array<array-key, mixed> $state
     */
    private function describeTranslation(array $state): string
    {
        if (($state['exists'] ?? false) !== true) {
            return 'not translated';
        }
        $description = $this->describeVisibility($state);
        return $description === 'no restricting setting' ? 'translated' : $description;
    }

    /**
     * @param array<array-key, mixed> $facts
     */
    private function describeVisibility(array $facts): string
    {
        $reasons = is_array($facts['reasons'] ?? null) ? $facts['reasons'] : [];
        $parts = [];
        if (in_array('hidden', $reasons, true)) {
            $parts[] = 'hidden';
        }
        if (in_array('scheduled', $reasons, true)) {
            $parts[] = 'scheduled, starts ' . $this->formatValue('', $facts['starttime'] ?? '');
        }
        if (in_array('expired', $reasons, true)) {
            $parts[] = 'expired, ended ' . $this->formatValue('', $facts['endtime'] ?? '');
        } elseif (isset($facts['endtime']) && is_string($facts['endtime'])) {
            $parts[] = 'ends ' . $facts['endtime'];
        }
        if (in_array('accessRestricted', $reasons, true)) {
            $groups = $this->joinItems(
                is_array($facts['frontendGroups'] ?? null) ? $facts['frontendGroups'] : [],
                ', ',
                static fn(array $group): string => ($group['title'] ?? '') . ' [' . ($group['id'] ?? '') . ']',
            );
            $notListed = (int)($facts['frontendGroupsNotListed'] ?? 0);
            $parts[] = 'frontend access: ' . $groups . ($notListed > 0 ? ', ' . $notListed . ' more' : '');
        }
        if (($facts['hiddenInMenu'] ?? false) === true) {
            $parts[] = 'hidden in menus';
        }
        if (is_string($facts['workspaceState'] ?? null)) {
            $parts[] = 'workspace: ' . $facts['workspaceState'];
        }
        return $parts !== [] ? implode('; ', $parts) : 'no restricting setting';
    }

    /**
     * The file checks as readable rows: the reported file, or the file
     * references of the reported record with their problems.
     *
     * @param array<array-key, mixed> $checks
     * @return array<string, string>
     */
    private function buildFileCheckRows(array $checks): array
    {
        $rows = [];
        $file = $checks['file'] ?? null;
        if (is_array($file)) {
            $problems = array_diff($this->fileProblems($file), ['notInStorage']);
            $storageCheck = self::STORAGE_CHECKS[$file['storageCheck'] ?? ''] ?? '';
            // File metadata names the file it stands for; a reported file is the subject itself
            $label = isset($file['uid']) ? sprintf('File "%s" [sys_file:%s]', $file['name'] ?? '', $file['uid']) : 'Reported file';
            $rows[$label] = implode('; ', array_filter([$storageCheck, $this->describeFileProblems($problems)]));
        }
        $references = $checks['references'] ?? null;
        if (is_array($references)) {
            $summary = sprintf('%d checked', (int)($references['checked'] ?? 0));
            $notChecked = (int)($references['notChecked'] ?? 0);
            if ($notChecked > 0) {
                $summary .= sprintf(", %d not checked (outside the reporter's file mounts)", $notChecked);
            }
            $overLimit = (int)($references['overLimit'] ?? 0);
            if ($overLimit > 0) {
                $summary .= sprintf(', %d not checked (limit reached)', $overLimit);
            }
            $rows['File references'] = $summary;
            foreach (is_array($references['problems'] ?? null) ? $references['problems'] : [] as $reference) {
                if (!is_array($reference)) {
                    continue;
                }
                $label = sprintf('%s [%s], reference %s', $reference['fieldLabel'] ?? '', $reference['field'] ?? '', $reference['reference'] ?? '');
                $file = is_array($reference['file'] ?? null) ? $reference['file'] : [];
                $description = $this->describeFileProblems($this->fileProblems($reference));
                $rows[$label] = $file !== [] ? sprintf('"%s" [sys_file:%s]: %s', $file['name'] ?? '', $file['uid'] ?? '', $description) : $description;
            }
            $notListed = (int)($references['problemsNotListed'] ?? 0);
            if ($notListed > 0) {
                $rows['Further references with problems'] = (string)$notListed;
            }
        }
        if ($rows !== []) {
            $rows['Note'] = self::FILE_CHECKS_NOTE;
        }
        return $rows;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<string>
     */
    private function fileProblems(array $data): array
    {
        return array_values(array_filter(is_array($data['problems'] ?? null) ? $data['problems'] : [], is_string(...)));
    }

    /**
     * @param array<array-key, string> $problems
     */
    private function describeFileProblems(array $problems): string
    {
        $parts = [];
        foreach (self::FILE_PROBLEMS as $problem => $description) {
            if (in_array($problem, $problems, true)) {
                $parts[] = $description;
            }
        }
        return $parts !== [] ? implode('; ', $parts) : ($problems === [] ? '' : implode('; ', $problems));
    }

    /**
     * @param list<array{title: string, rows: array<string, string>}> $sections
     */
    private function addSection(array &$sections, string $title, mixed $data): void
    {
        if (!is_array($data) || $data === []) {
            return;
        }
        $rows = [];
        if (array_is_list($data)) {
            foreach ($data as $index => $item) {
                $formatted = is_array($item) ? $this->formatAssociative($item, '; ') : $this->formatValue('', $item);
                if ($formatted !== '') {
                    $rows['#' . ($index + 1)] = $formatted;
                }
            }
        } else {
            foreach ($data as $key => $value) {
                $formatted = $this->formatValue((string)$key, $value);
                if ($formatted !== '') {
                    $rows[$this->label((string)$key)] = $formatted;
                }
            }
        }
        if ($rows !== []) {
            $sections[] = ['title' => $title, 'rows' => $rows];
        }
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function formatAssociative(array $value, string $separator): string
    {
        $parts = [];
        foreach ($value as $key => $item) {
            $formatted = is_array($item)
                ? (string)json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
                : $this->formatValue((string)$key, $item);
            if ($formatted !== '') {
                $parts[] = $key . ': ' . $formatted;
            }
        }
        return implode($separator, $parts);
    }

    /**
     * @param array<array-key, mixed> $items
     * @param \Closure(array<array-key, mixed>): string $formatter
     */
    private function joinItems(array $items, string $separator, \Closure $formatter): string
    {
        $parts = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $parts[] = $formatter($item);
            }
        }
        return implode($separator, $parts);
    }

    private function label(string $key): string
    {
        return self::LABELS[$key] ?? $this->humanize($key);
    }

    private function humanize(string $key): string
    {
        $words = strtolower(trim((string)preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace('_', ' ', $key))));
        return ucfirst($words);
    }
}
