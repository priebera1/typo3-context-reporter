<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery\Email;

use Priebera\ContextReporter\Domain\ReportSource;

/**
 * What a report email says, built once from the report payload and the facts
 * of this delivery, for both parts of the email: the HTML part (a fixed Fluid
 * template, which escapes every value) and the extra markers of the
 * customisable plain-text part.
 *
 * Only facts that are true for this email are included: the Hub reference
 * after the Hub acknowledged the report, the attachments that are really
 * attached, the reply note when Reply-To is set. Links are only http(s) URLs.
 * Times are given in the time zone of the TYPO3 server, named, and in UTC.
 *
 * @internal
 */
final class ReportEmailModel
{
    private const PRODUCT = 'Context Reporter for TYPO3';

    /**
     * Nouns of the object link ("Open content element in TYPO3").
     */
    private const OBJECT_NOUNS = [
        'tt_content' => 'content element',
        'pages' => 'page',
        'sys_file_metadata' => 'file metadata',
        'sys_file_reference' => 'file reference',
    ];

    /**
     * @param array<string, mixed> $payload The report payload without screenshot content
     * @param array{groups: list<array{title: string, items: list<string>}>, note: string}|null $findings
     * @param list<array{title: string, rows: array<string, string>}> $sections The technical context
     * @param array{reference: string, url: string}|null $hub Only after the Hub acknowledged the report
     * @param array{filename: string, mediaType: string, size: int, width: int, height: int}|null $screenshot Only when attached
     * @param bool $jsonAttached Whether the JSON report is attached
     * @param string $replyToName The name replies go to; empty without Reply-To
     * @return array<string, mixed>
     */
    public static function create(
        array $payload,
        ?array $findings,
        array $sections,
        ?array $hub,
        ?array $screenshot,
        bool $jsonAttached,
        string $replyToName,
        string $serverTimeZone,
    ): array {
        $project = self::section($payload, 'project');
        $subject = self::section($payload, 'subject');
        $context = self::section($payload, 'context');
        $reportId = self::string($payload, 'id');
        $title = self::string($payload, 'title');
        $source = ReportSource::tryFrom(self::string($payload, 'source'))?->getLabel() ?? '';
        $environment = self::string($project, 'environment');
        $projectName = self::string($project, 'name');
        $created = self::created(self::string($payload, 'createdAt'), $serverTimeZone);
        $reporter = self::reporter(self::section($payload, 'reporter'));
        $object = self::object($subject, $context);
        $reportUrl = self::url(self::string(self::section($payload, 'links'), 'report'));
        if ($hub !== null && preg_match('/^[A-Z]{1,5}-[0-9]{1,9}$/D', $hub['reference']) !== 1) {
            $hub = null;
        }
        if ($hub !== null) {
            $hub['url'] = self::url($hub['url']);
        }

        $attachments = [];
        if ($screenshot !== null) {
            $type = $screenshot['mediaType'] === 'image/jpeg' ? 'JPEG' : 'PNG';
            $attachments[] = [
                'tag' => $type,
                'name' => $screenshot['filename'],
                'detail' => sprintf('Screenshot, %d × %d px, %s', $screenshot['width'], $screenshot['height'], self::bytes($screenshot['size'])),
                'text' => sprintf('%s (screenshot, %s, %d × %d px, %s)', $screenshot['filename'], $type, $screenshot['width'], $screenshot['height'], self::bytes($screenshot['size'])),
            ];
        }
        if ($jsonAttached) {
            $attachments[] = [
                'tag' => 'JSON',
                'name' => $reportId . '.json',
                'detail' => 'The complete, machine-readable report',
                'text' => $reportId . '.json (the complete, machine-readable report)',
            ];
        }

        $findingCount = 0;
        foreach ($findings['groups'] ?? [] as $group) {
            $findingCount += count($group['items']);
        }
        $projectLabel = $projectName !== '' ? $projectName . ($environment !== '' ? ' (' . $environment . ')' : '') : '';
        $sentBy = 'Sent by ' . self::PRODUCT . ($projectLabel !== '' ? ' from ' . $projectLabel : '') . '.';
        $reply = $replyToName !== '' ? 'Reply to this email to answer ' . $replyToName . '.' : '';

        $links = [];
        if ($reportUrl !== '') {
            $links[] = 'Report in TYPO3: ' . $reportUrl;
        }
        if ($object['action'] !== null) {
            $links[] = ucfirst($object['action']['noun']) . ' in TYPO3: ' . $object['action']['url'];
        }
        if ($hub !== null) {
            $links[] = 'Context Reporter Hub: ' . $hub['reference'] . ($hub['url'] !== '' ? ' ' . $hub['url'] : '');
        }

        return [
            'subjectLine' => '',
            'preheader' => implode(' · ', array_filter([
                $object['headline'],
                $findingCount === 0 ? 'no findings' : ($findingCount === 1 ? '1 finding' : $findingCount . ' findings'),
                $reportId,
            ])),
            'project' => ['name' => $projectName, 'environment' => $environment, 'label' => $projectLabel],
            'title' => $title,
            'reportId' => $reportId,
            'hub' => $hub,
            'source' => $source,
            'created' => $created,
            'reporter' => $reporter + ['via' => $source],
            'description' => self::string($payload, 'description'),
            'object' => $object,
            'findings' => $findings !== null && $findings['groups'] !== [] ? $findings : null,
            'reportUrl' => $reportUrl,
            'attachments' => $attachments,
            'jsonAttached' => $jsonAttached,
            'technical' => self::columns($sections),
            'footer' => ['sentBy' => $sentBy, 'reply' => $reply],
            'markers' => [
                'report.created' => $created['text'],
                'report.createdLocal' => $created['localText'],
                'reporter.display' => $reporter['text'],
                'project.label' => $projectLabel,
                'hub.reference' => $hub['reference'] ?? '',
                'hub.url' => $hub['url'] ?? '',
                'email.description' => self::string($payload, 'description') !== '' ? self::string($payload, 'description') : 'No description was given.',
                'email.links' => $links !== [] ? implode("\n", $links) : 'No links: the report URL is not known.',
                'email.attachments' => $attachments !== [] ? implode("\n", array_column($attachments, 'text')) : 'None',
                'email.footer' => trim($sentBy . "\n" . $reply),
            ],
        ];
    }

    /**
     * @return array{local: string, utc: string, text: string, localText: string, isUtc: bool}
     */
    private static function created(string $isoDate, string $serverTimeZone): array
    {
        try {
            $date = new \DateTimeImmutable($isoDate !== '' ? $isoDate : 'now');
            $zone = new \DateTimeZone($serverTimeZone !== '' ? $serverTimeZone : 'UTC');
        } catch (\Exception) {
            return ['local' => $isoDate, 'utc' => '', 'text' => $isoDate, 'localText' => $isoDate, 'isUtc' => true];
        }
        $utc = $date->setTimezone(new \DateTimeZone('UTC'));
        $local = $date->setTimezone($zone);
        $isUtc = $local->getOffset() === 0 && in_array($zone->getName(), ['UTC', 'Etc/UTC', 'GMT', 'Etc/GMT', 'Z'], true);
        $utcText = $utc->format('Y-m-d H:i') . ' UTC';
        return [
            'local' => $isUtc ? $utc->format('j M Y, H:i') . ' UTC' : $local->format('j M Y, H:i T'),
            'utc' => $isUtc ? '' : $utcText,
            'text' => $isUtc ? $utcText : $local->format('Y-m-d H:i T') . ' (' . $utc->format('H:i') . ' UTC)',
            'localText' => $isUtc ? $utcText : $local->format('Y-m-d H:i T'),
            'isUtc' => $isUtc,
        ];
    }

    /**
     * The reporter as the privacy settings allow: a hidden real name is said,
     * not left blank.
     *
     * @param array<array-key, mixed> $reporter
     * @return array{primary: string, secondary: string, text: string}
     */
    private static function reporter(array $reporter): array
    {
        $name = self::string($reporter, 'realName');
        $username = self::string($reporter, 'username');
        $uid = self::string($reporter, 'uid');
        return match (true) {
            $name !== '' && $username !== '' => ['primary' => $name, 'secondary' => $username, 'text' => $name . ' (' . $username . ')'],
            $name !== '' => ['primary' => $name, 'secondary' => '', 'text' => $name],
            $username !== '' => ['primary' => $username, 'secondary' => 'real name not shared', 'text' => $username . ' (real name not shared)'],
            $uid !== '' => ['primary' => 'Backend user #' . $uid, 'secondary' => 'name not shared', 'text' => 'backend user #' . $uid . ' (name not shared)'],
            default => ['primary' => 'Not shared', 'secondary' => '', 'text' => 'a backend user (not shared)'],
        };
    }

    /**
     * The reported object: what it is, where it is, and a link to open it.
     *
     * @param array<array-key, mixed> $subject
     * @param array<array-key, mixed> $context
     * @return array{label: string, meta: string, identifier: string, headline: string, facts: list<array{label: string, value: string, url: string}>, factRows: list<list<array{label: string, value: string, url: string}>>, action: array{noun: string, label: string, url: string}|null}
     */
    private static function object(array $subject, array $context): array
    {
        $type = self::string($subject, 'type');
        $table = self::string($subject, 'table');
        $uid = self::string($subject, 'uid');
        $record = self::section($context, 'record');
        $page = self::section($context, 'page');
        $module = self::section(self::section($context, 'backend'), 'module');
        $moduleLabel = implode(' › ', array_filter([self::string($module, 'group'), self::string($module, 'title')]));
        $typeLabel = self::string($subject, 'typeLabel');
        $recordType = self::section($record, 'type');
        $recordTypeLabel = $type === 'record' ? (self::string($recordType, 'label') ?: self::string($recordType, 'value')) : '';

        $label = self::string($subject, 'label');
        if ($label === '') {
            $label = $type === 'backend' ? ($moduleLabel !== '' ? $moduleLabel : 'TYPO3 backend') : ($typeLabel !== '' ? $typeLabel : 'Reported object');
        }
        $identifier = $table !== '' && $uid !== '' ? $table . ':' . $uid : self::string($subject, 'identifier');
        $meta = implode(' · ', array_values(array_filter([$type === 'backend' ? 'Backend module' : $typeLabel, $recordTypeLabel])));

        $facts = [];
        $add = static function (string $factLabel, string $value, string $url = '') use (&$facts): void {
            if ($value !== '') {
                $facts[] = ['label' => $factLabel, 'value' => $value, 'url' => $url];
            }
        };
        if ($type !== 'page' && $page !== []) {
            $add('Page', trim(self::string($page, 'title') . (self::string($page, 'uid') !== '' ? ' [' . self::string($page, 'uid') . ']' : '')));
        }
        $add('Storage', self::string(self::section($context, 'storage'), 'name'));
        if ($type === 'file') {
            $add('Folder', self::string(self::section($context, 'folder'), 'identifier'));
        }
        $add('Site', self::string(self::section($context, 'site'), 'identifier'));
        $add('Language', self::string(self::section($context, 'language'), 'title'));
        $add('Workspace', self::string(self::section($context, 'workspace'), 'title'));
        if ($type !== 'backend') {
            $add('Module', $moduleLabel);
        }
        $frontendUrl = self::url(self::string($page, 'frontendUrl'));
        $add('Website address', $frontendUrl !== '' ? (string)preg_replace('~^https?://~i', '', $frontendUrl) : '', $frontendUrl);

        $action = null;
        $backendUrl = self::url(self::string($subject, 'backendUrl'));
        if ($backendUrl !== '') {
            $noun = match ($type) {
                'page' => 'page',
                // The link of a file opens the file list at its folder: TYPO3 has no file view that works on both branches
                'file' => 'containing folder',
                'folder' => 'folder',
                'backend' => 'module',
                default => self::OBJECT_NOUNS[$table] ?? 'record',
            };
            $action = ['noun' => $noun, 'label' => 'Open ' . $noun . ' in TYPO3', 'url' => $backendUrl];
        }

        $headline = trim(($typeLabel !== '' && $type !== 'backend' ? $typeLabel . ' ' : '') . '“' . $label . '”');
        if ($type !== 'page' && self::string($page, 'title') !== '') {
            $headline .= ' on page ' . self::string($page, 'title');
        }
        return ['label' => $label, 'meta' => $meta, 'identifier' => $identifier, 'headline' => $headline, 'facts' => $facts, 'factRows' => array_chunk($facts, 2), 'action' => $action];
    }

    /**
     * The technical sections in two columns of about equal length.
     *
     * @param list<array{title: string, rows: array<string, string>}> $sections
     * @return array{left: list<array{title: string, rows: list<array{label: string, value: string}>}>, right: list<array{title: string, rows: list<array{label: string, value: string}>}>, rowCount: int}
     */
    private static function columns(array $sections): array
    {
        $total = 0;
        $prepared = [];
        foreach ($sections as $section) {
            $rows = [];
            foreach ($section['rows'] as $rowLabel => $value) {
                $rows[] = ['label' => (string)$rowLabel, 'value' => $value];
            }
            $prepared[] = ['title' => $section['title'], 'rows' => $rows];
            $total += count($rows) + 2;
        }
        $left = [];
        $right = [];
        $count = 0;
        foreach ($prepared as $section) {
            if ($count < $total / 2) {
                $left[] = $section;
                $count += count($section['rows']) + 2;
            } else {
                $right[] = $section;
            }
        }
        return ['left' => $left, 'right' => $right, 'rowCount' => $total];
    }

    /**
     * Only http(s) links without user information; anything else is no link.
     */
    public static function url(string $url): string
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return '';
        }
        $parts = parse_url($url);
        return is_array($parts)
            && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            && (string)($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
            ? $url : '';
    }

    private static function bytes(int $size): string
    {
        return match (true) {
            $size >= 1048576 => number_format($size / 1048576, 1, '.', '') . ' MB',
            $size >= 1024 => number_format($size / 1024, 1, '.', '') . ' KB',
            default => $size . ' bytes',
        };
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function section(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        return is_scalar($value) && !is_bool($value) ? trim((string)$value) : '';
    }
}
