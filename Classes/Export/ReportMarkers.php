<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Export;

use Priebera\ContextReporter\Domain\ReportSource;

/**
 * Provides the marker values for email templates. Every documented marker is
 * always present, with an empty value when the report has no such data.
 *
 * @internal
 */
final readonly class ReportMarkers
{
    public function __construct(
        private ContextDetailsFormatter $detailsFormatter,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    public function fromPayload(array $payload): array
    {
        $project = $this->section($payload, 'project');
        $reporter = $this->section($payload, 'reporter');
        $subject = $this->section($payload, 'subject');
        $context = $this->section($payload, 'context');
        $page = $this->nested($context, 'page');
        $record = $this->nested($context, 'record');
        $site = $this->nested($context, 'site');
        $file = $this->nested($context, 'file');
        $folder = $this->nested($context, 'folder');
        $backend = $this->nested($context, 'backend');
        $system = $this->section($payload, 'system');
        $browser = $this->section($payload, 'browser');

        $groups = [];
        foreach (is_array($reporter['groups'] ?? null) ? $reporter['groups'] : [] as $group) {
            if (is_array($group) && is_string($group['title'] ?? null)) {
                $groups[] = $group['title'];
            }
        }

        $module = $this->nested($backend, 'module');
        $recordType = $this->nested($record, 'type');
        $viewport = $this->nested($browser, 'viewport');
        $subjectLabel = $this->string($subject, 'label');
        $subjectTypeLabel = $this->string($subject, 'typeLabel');

        return [
            'report.id' => $this->string($payload, 'id'),
            'report.title' => $this->string($payload, 'title'),
            'report.description' => $this->string($payload, 'description'),
            'report.createdAt' => $this->formatDate($this->string($payload, 'createdAt')),
            'report.source' => ReportSource::tryFrom($this->string($payload, 'source'))?->getLabel() ?? '',
            'report.url' => $this->string($this->section($payload, 'links'), 'report'),

            'project.name' => $this->string($project, 'name'),
            'project.identifier' => $this->string($project, 'identifier'),
            'project.environment' => $this->string($project, 'environment'),
            'project.url' => $this->string($project, 'backendUrl'),

            'reporter.name' => $this->reporterName($reporter),
            'reporter.username' => $this->string($reporter, 'username'),
            'reporter.email' => $this->string($reporter, 'email'),
            'reporter.uid' => $this->string($reporter, 'uid'),
            'reporter.groups' => implode(', ', $groups),

            'page.uid' => $this->string($page, 'uid'),
            'page.title' => $this->string($page, 'title'),
            'page.slug' => $this->string($page, 'slug'),
            'page.url' => $this->string($page, 'frontendUrl'),
            'page.backendUrl' => $this->string($page, 'backendUrl'),

            'record.table' => $this->string($record, 'table'),
            'record.tableTitle' => $this->string($record, 'tableTitle'),
            'record.uid' => $this->string($record, 'uid'),
            'record.label' => $this->string($record, 'label'),
            'record.type' => $this->string($recordType, 'label') !== '' ? $this->string($recordType, 'label') : $this->string($recordType, 'value'),
            'record.backendUrl' => $this->string($record, 'backendUrl'),

            'file.uid' => $this->string($file, 'uid'),
            'file.name' => $this->string($file, 'name'),
            'file.identifier' => $this->combinedIdentifier($file),
            'file.mimeType' => $this->string($file, 'mimeType'),
            'file.backendUrl' => $this->string($file, 'backendUrl'),

            'folder.identifier' => $this->combinedIdentifier($folder),
            'folder.name' => $this->string($folder, 'name'),

            'storage.name' => $this->string($this->nested($context, 'storage'), 'name'),

            'site.identifier' => $this->string($site, 'identifier'),
            'site.base' => $this->string($site, 'base'),

            'context.summary' => $this->string($payload, 'summary'),
            'context.subject' => trim($subjectTypeLabel . ($subjectLabel !== '' ? ' "' . $subjectLabel . '"' : '')),
            'context.subjectUrl' => $this->string($subject, 'backendUrl'),
            'context.details' => $this->detailsFormatter->toText($payload),
            'context.language' => $this->string($this->nested($context, 'language'), 'title'),
            'context.workspace' => $this->string($this->nested($context, 'workspace'), 'title'),
            'context.module' => implode(' › ', array_filter([$this->string($module, 'group'), $this->string($module, 'title')])),

            'system.typo3Version' => $this->string($system, 'typo3Version'),
            'system.phpVersion' => $this->string($system, 'phpVersion'),
            'system.applicationContext' => $this->string($system, 'applicationContext'),

            'browser.summary' => $this->string($browser, 'summary'),
            'browser.userAgent' => $this->string($browser, 'userAgent'),
            'browser.viewport' => isset($viewport['width'], $viewport['height']) ? $viewport['width'] . '×' . $viewport['height'] : '',
            'browser.language' => $this->string($browser, 'language'),
        ];
    }

    /**
     * @param array<array-key, mixed> $reporter
     */
    private function reporterName(array $reporter): string
    {
        foreach (['realName', 'username'] as $key) {
            if ($this->string($reporter, $key) !== '') {
                return $this->string($reporter, $key);
            }
        }
        if ($this->string($reporter, 'uid') !== '') {
            return 'Backend user #' . $this->string($reporter, 'uid');
        }
        return '(not shared)';
    }

    /**
     * "<storage UID>:<identifier>" of a file or folder section.
     *
     * @param array<array-key, mixed> $resource
     */
    private function combinedIdentifier(array $resource): string
    {
        $storageUid = $this->string($resource, 'storageUid');
        $identifier = $this->string($resource, 'identifier');
        return $storageUid !== '' && $identifier !== '' ? $storageUid . ':' . $identifier : '';
    }

    private function formatDate(string $isoDate): string
    {
        if ($isoDate === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($isoDate))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC';
        } catch (\Exception) {
            return $isoDate;
        }
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function section(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function nested(array $data, string $key): array
    {
        return $this->section($data, $key);
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
