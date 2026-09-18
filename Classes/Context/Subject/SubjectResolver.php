<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Subject;

use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\Location\BackendLocation;
use Priebera\ContextReporter\Context\Routing\ResolvedRoute;
use Priebera\ContextReporter\Domain\SubjectType;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Determines which TYPO3 object a report is about and enforces that the
 * reporter may see it. Nothing about a page or record is collected unless it
 * passed through here.
 *
 * Precedence: explicit target (context menu, record list, editing form, file
 * list) > record open in the editing form > page of a page tree based module
 * > folder of a folder tree based module > backend view only.
 *
 * @internal
 */
final readonly class SubjectResolver
{
    public const PAGE_TREE_COMPONENT = '@typo3/backend/tree/page-tree-element';
    public const FOLDER_TREE_COMPONENT = '@typo3/backend/tree/file-storage-tree-container';

    public function __construct(
        private RecordAccess $recordAccess,
        private FileAccess $fileAccess,
        private ModuleProvider $moduleProvider,
    ) {}

    /**
     * @throws SubjectNotAvailableException when an explicitly requested object is not available
     */
    public function resolve(CollectionRequest $request, ?ResolvedRoute $route, BackendUserAuthentication $backendUser): ResolvedSubject
    {
        $target = $request->target;
        if ($target->isExplicit()) {
            $subject = match ($target->type) {
                SubjectType::File => $this->resolveFile($target->uid, $backendUser),
                SubjectType::Folder => $this->resolveFolder($target->identifier, $backendUser),
                default => $this->resolveRecord($target->table, $target->uid, $backendUser),
            };
            if ($subject === null) {
                throw new SubjectNotAvailableException('The requested object is not available.', 1757930301);
            }
            return $subject;
        }
        return $this->resolveFromLocation($request->location, $route, $backendUser);
    }

    private function resolveFromLocation(BackendLocation $location, ?ResolvedRoute $route, BackendUserAuthentication $backendUser): ResolvedSubject
    {
        if ($route !== null && $route->isRecordEditing() && $location->editedRecords !== []) {
            $first = $location->editedRecords[0];
            $subject = $first['command'] === 'new'
                ? $this->resolveNewRecord($first['table'], $first['uid'], $backendUser)
                : $this->resolveRecord($first['table'], $first['uid'], $backendUser);
            if ($subject !== null) {
                return $subject;
            }
        }

        $pageUid = $location->pageId;
        $folderIdentifier = '';
        $moduleIdentifier = $route !== null && $route->moduleIdentifier !== '' ? $route->moduleIdentifier : $location->moduleIdentifier;
        if ($moduleIdentifier !== '') {
            $navigationComponent = $this->moduleProvider->getModule($moduleIdentifier, $backendUser)?->getNavigationComponent() ?? '';
            $pageUid = $navigationComponent === self::PAGE_TREE_COMPONENT ? ($location->pageId ?? $location->pageTreeSelection) : null;
            $folderIdentifier = $navigationComponent === self::FOLDER_TREE_COMPONENT ? $location->folderIdentifier : '';
        }
        $subject = match (true) {
            $pageUid !== null => $this->resolveRecord('pages', $pageUid, $backendUser),
            $folderIdentifier !== '' => $this->resolveFolder($folderIdentifier, $backendUser),
            default => null,
        };
        return $subject ?? ResolvedSubject::backend();
    }

    private function resolveFile(int $uid, BackendUserAuthentication $backendUser): ?ResolvedSubject
    {
        $file = $this->fileAccess->findFile($uid, $backendUser);
        return $file !== null ? ResolvedSubject::file($file) : null;
    }

    private function resolveFolder(string $identifier, BackendUserAuthentication $backendUser): ?ResolvedSubject
    {
        $folder = $this->fileAccess->findFolder($identifier, $backendUser);
        return $folder !== null ? ResolvedSubject::folder($folder) : null;
    }

    private function resolveRecord(string $table, int $uid, BackendUserAuthentication $backendUser): ?ResolvedSubject
    {
        $record = $this->recordAccess->findRecord($table, $uid, $backendUser);
        if ($record === null) {
            return null;
        }
        // An offline version UID was resolved to its record
        $uid = (int)$record['uid'];
        if ($table === 'pages') {
            return new ResolvedSubject(SubjectType::Page, 'pages', $uid, $record, $uid, $record);
        }
        $pageUid = (int)($record['pid'] ?? 0);
        $page = $pageUid > 0 ? ($this->recordAccess->findPage($pageUid, $backendUser) ?? []) : [];
        return new ResolvedSubject(SubjectType::Record, $table, $uid, $record, $pageUid, $page);
    }

    /**
     * @param int $position FormEngine notation: a page uid, or a negative record uid to insert after
     */
    private function resolveNewRecord(string $table, int $position, BackendUserAuthentication $backendUser): ?ResolvedSubject
    {
        if (!$this->recordAccess->allowsNewRecord($table, $backendUser)) {
            return null;
        }
        $pageUid = $position;
        if ($position < 0) {
            // The record to insert after must be accessible itself
            $reference = $this->recordAccess->findRecord($table, abs($position), $backendUser);
            if ($reference === null) {
                return null;
            }
            $pageUid = (int)($reference['pid'] ?? 0);
        }
        if (!$this->recordAccess->isAllowedLocation($table, $pageUid, $backendUser)) {
            return null;
        }
        $page = $pageUid > 0 ? ($this->recordAccess->findPage($pageUid, $backendUser) ?? []) : [];
        return new ResolvedSubject(SubjectType::Record, $table, 0, [], $pageUid, $page, true);
    }
}
