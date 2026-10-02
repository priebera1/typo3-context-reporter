<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Presentation;

use Priebera\ContextReporter\Domain\ContextDocument;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * The findings of a report: short notices about the diagnostics of the
 * context document, in fixed groups (website address, placement,
 * visibility, files, permissions), with one note that they are stored
 * settings and permission facts, not a check of the website and not an
 * editing decision. There are no severity levels.
 *
 * The report dialog and the report detail show a few notices per group and
 * point to the technical details for the rest; exports list all of them.
 * Reports without diagnostics (e.g. created before 0.2.0) have no findings.
 *
 * @internal
 */
final readonly class FindingsBuilder
{
    use NoticeTrait;

    public const PRESENTED_PER_GROUP = 5;

    private const ICONS = [
        'website' => 'actions-globe',
        'placement' => 'content-container-columns-2',
        'visibility' => 'actions-eye',
        'files' => 'actions-file',
        'access' => 'actions-lock',
    ];

    public function __construct(
        private RoutingNoticeBuilder $routingNotices,
        private PlacementNoticeBuilder $placementNotices,
        private VisibilityNoticeBuilder $visibilityNotices,
        private FileCheckNoticeBuilder $fileCheckNotices,
        private FileUsageNoticeBuilder $fileUsageNotices,
        private PermissionNoticeBuilder $permissionNotices,
    ) {}

    /**
     * @param int|null $limit Notices per group; null for all
     * @return array{title: string, groups: list<array{key: string, title: string, icon: string, items: list<string>, more: int, moreText: string}>, note: string}|null Null without findings
     */
    public function build(ContextDocument $document, LanguageService $languageService, ?int $limit = self::PRESENTED_PER_GROUP): ?array
    {
        $notices = [
            'website' => $this->routingNotices->build($document, $languageService),
            'placement' => $this->placementNotices->build($document, $languageService),
            'visibility' => $this->visibilityNotices->build($document, $languageService),
            'files' => [...$this->fileCheckNotices->build($document, $languageService), ...$this->fileUsageNotices->build($document, $languageService)],
            'access' => $this->permissionNotices->build($document, $languageService),
        ];
        $groups = [];
        foreach ($notices as $key => $items) {
            if ($items === []) {
                continue;
            }
            $shown = $limit !== null ? array_slice($items, 0, $limit) : $items;
            $more = count($items) - count($shown);
            $groups[] = [
                'key' => $key,
                'title' => $this->label('findings.group.' . $key, $languageService),
                'icon' => self::ICONS[$key],
                'items' => $shown,
                'more' => $more,
                'moreText' => $more > 0 ? sprintf($this->label('findings.more', $languageService), $more) : '',
            ];
        }
        if ($groups === []) {
            return null;
        }
        return [
            'title' => $this->label('findings.title', $languageService),
            'groups' => $groups,
            'note' => $this->label('findings.note', $languageService),
        ];
    }
}
