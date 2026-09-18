<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Summary;

/**
 * One line that tells a developer what the report is about, e.g.
 * Page Content "Hero" [tt_content:12] (Text & Media) on page "Home" [1] · site main · language English · workspace Live
 * File "logo.png" [sys_file:1] (image/png) in 1:/user_upload/ · storage fileadmin · workspace Live
 *
 * @internal
 */
final class ContextSummaryBuilder
{
    /**
     * @param array<string, mixed> $document
     */
    public function build(array $document): string
    {
        $subject = $this->array($document, 'subject');
        $context = $this->array($document, 'context');
        $type = $this->string($subject, 'type');

        $parts = [$this->describeSubject($type, $subject, $context)];

        $storage = $this->string($this->array($context, 'storage'), 'name');
        if ($storage !== '') {
            $parts[] = 'storage ' . $storage;
        }
        $site = $this->string($this->array($context, 'site'), 'identifier');
        if ($site !== '') {
            $parts[] = 'site ' . $site;
        }
        $language = $this->array($context, 'language');
        if ($language !== []) {
            $title = $this->string($language, 'title');
            $parts[] = 'language ' . ($title !== '' ? $title : $this->string($language, 'id'));
        }
        $workspace = $this->string($this->array($context, 'workspace'), 'title');
        if ($workspace !== '') {
            $parts[] = 'workspace ' . $workspace;
        }
        $module = $this->describeModule($this->array($this->array($context, 'backend'), 'module'));
        if ($type !== 'backend' && $module !== '') {
            $parts[] = 'module ' . $module;
        }
        $form = $this->array($context, 'formEngine');
        if ($form !== []) {
            $count = count($this->array($form, 'records'));
            $parts[] = match (true) {
                $this->string($form, 'mode') === 'new' => 'new record form',
                $count > 1 => 'editing form with ' . $count . ' records',
                default => 'editing form',
            };
        }
        return implode(' · ', array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    /**
     * @param array<array-key, mixed> $subject
     * @param array<array-key, mixed> $context
     */
    private function describeSubject(string $type, array $subject, array $context): string
    {
        $page = $this->array($context, 'page');
        $pageText = $page !== [] ? sprintf('page "%s" [%s]', $this->string($page, 'title'), $this->string($page, 'uid')) : '';

        if ($type === 'record') {
            $record = $this->array($context, 'record');
            $tableTitle = $this->string($record, 'tableTitle') ?: $this->string($subject, 'table');
            if (($record['isNew'] ?? $subject['isNew'] ?? false) === true) {
                return trim('New ' . $tableTitle . ' record' . ($pageText !== '' ? ' on ' . $pageText : ''));
            }
            $label = $this->string($record, 'label');
            $text = sprintf('%s%s [%s:%s]', $tableTitle, $label !== '' ? ' "' . $label . '"' : '', $this->string($record, 'table'), $this->string($record, 'uid'));
            $typeLabel = $this->string($this->array($record, 'type'), 'label');
            if ($typeLabel !== '') {
                $text .= ' (' . $typeLabel . ')';
            }
            return $text . ($pageText !== '' ? ' on ' . $pageText : '');
        }

        if ($type === 'file') {
            $file = $this->array($context, 'file');
            $text = sprintf('File "%s" [sys_file:%s]', $this->string($subject, 'label'), $this->string($subject, 'uid'));
            $mimeType = $this->string($file, 'mimeType');
            if ($mimeType !== '') {
                $text .= ' (' . $mimeType . ')';
            }
            $folder = $this->array($context, 'folder');
            if ($folder !== []) {
                $text .= ' in ' . $this->string($folder, 'storageUid') . ':' . $this->string($folder, 'identifier');
            }
            return $text;
        }

        if ($type === 'folder') {
            return sprintf('Folder "%s" [%s]', $this->string($subject, 'label'), $this->string($subject, 'identifier'));
        }

        if ($type === 'page') {
            if ($pageText === '') {
                return 'Page';
            }
            $text = 'P' . substr($pageText, 1);
            $doktype = $this->string($page, 'doktype');
            $doktypeLabel = $this->string($page, 'doktypeLabel');
            if ($doktype !== '' && $doktype !== '1' && $doktypeLabel !== '') {
                $text .= ' (' . $doktypeLabel . ')';
            }
            return $text;
        }

        $backend = $this->array($context, 'backend');
        $module = $this->describeModule($this->array($backend, 'module'));
        if ($module !== '') {
            return 'Backend module "' . $module . '"';
        }
        $route = $this->string($this->array($backend, 'route'), 'identifier');
        return $route !== '' ? 'Backend view "' . $route . '"' : 'TYPO3 backend';
    }

    /**
     * @param array<array-key, mixed> $module
     */
    private function describeModule(array $module): string
    {
        return implode(' › ', array_filter([$this->string($module, 'group'), $this->string($module, 'title')]));
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
     */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        return is_scalar($value) && !is_bool($value) ? (string)$value : '';
    }
}
