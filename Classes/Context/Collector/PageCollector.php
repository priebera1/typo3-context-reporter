<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Priebera\ContextReporter\Backend\BackendLinkBuilder;
use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Priebera\ContextReporter\Context\Routing\FrontendAddressResolver;
use Priebera\ContextReporter\Context\ServerTime;
use Priebera\ContextReporter\Context\Subject\RecordAccess;
use Priebera\ContextReporter\Context\Tca\TcaInspector;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The page the subject is, or lives on. Only allowlisted page properties are
 * collected, never page content.
 *
 * @internal
 */
#[AsTaggedItem(priority: 90)]
final readonly class PageCollector implements ContextCollectorInterface
{
    private const PAGE_MODULE = 'web_layout';
    private const MAX_ROOTLINE_DEPTH = 20;

    public function __construct(
        private TcaInspector $tca,
        private BackendLinkBuilder $links,
        private FrontendAddressResolver $addressResolver,
        private RecordAccess $recordAccess,
    ) {}

    public function getSectionKey(): string
    {
        return 'page';
    }

    public function collect(CollectionScope $scope): array
    {
        $subject = $scope->subject;
        if (!$subject->hasPage()) {
            return [];
        }
        $page = $subject->page;
        $uid = $subject->pageUid;
        $doktype = (int)($page['doktype'] ?? 0);

        $data = [
            'uid' => $uid,
            'pid' => (int)($page['pid'] ?? 0),
            'title' => (string)($page['title'] ?? ''),
        ];
        if (is_string($page['slug'] ?? null) && $page['slug'] !== '') {
            $data['slug'] = $page['slug'];
        }
        $data['doktype'] = $doktype;
        $doktypeLabel = $this->tca->getItemLabel('pages', 'doktype', (string)$doktype);
        if ($doktypeLabel !== '') {
            $data['doktypeLabel'] = $doktypeLabel;
        }
        $disabledField = $this->tca->getDisabledField('pages');
        if ($disabledField !== '' && isset($page[$disabledField])) {
            $data['hidden'] = (bool)$page[$disabledField];
        }
        $versionUid = (int)($page['_ORIG_uid'] ?? 0);
        if ($versionUid > 0 && $versionUid !== $uid) {
            $data['workspaceVersionUid'] = $versionUid;
        }
        $data += ServerTime::describeRecord($page, $this->tca->getCreationTimeField('pages'), $this->tca->getChangeTimeField('pages'));
        $rootline = $this->buildRootline($page, $scope);
        if ($rootline !== []) {
            $data['rootline'] = $rootline;
        }
        $address = $this->addressResolver->resolve($scope);
        $links = array_filter([
            'backendUrl' => $this->links->module(self::PAGE_MODULE, ['id' => $uid]),
            'editUrl' => $this->links->editRecord('pages', $uid),
            // Only an address that belongs to the page; "context.routing" explains a missing one
            'frontendUrl' => $address !== null ? $address->url : '',
        ]);
        return $data + $links;
    }

    /**
     * Page titles from the top to the page. The rootline stops at the first
     * page the reporter may not see, so no titles outside their mounts leak.
     *
     * @param array<string, mixed> $page
     * @return list<array{uid: int, title: string}>
     */
    private function buildRootline(array $page, CollectionScope $scope): array
    {
        $rootline = [];
        $current = $page;
        for ($depth = 0; $depth < self::MAX_ROOTLINE_DEPTH && $current !== null; $depth++) {
            array_unshift($rootline, ['uid' => (int)($current['uid'] ?? 0), 'title' => (string)($current['title'] ?? '')]);
            $parentUid = (int)($current['pid'] ?? 0);
            $current = $parentUid > 0 ? $this->recordAccess->findPage($parentUid, $scope->backendUser) : null;
        }
        return $rootline;
    }
}
