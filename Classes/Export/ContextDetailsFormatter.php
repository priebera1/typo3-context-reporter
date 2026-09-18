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
        'backend' => 'Backend',
        'recentErrors' => 'Recent backend errors',
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
            if (array_key_exists($key, $context)) {
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
