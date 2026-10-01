<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Visibility;

use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Reads the stored visibility settings of a record the way TYPO3 applies
 * them to website visitors: hidden, start and end time, and frontend user
 * groups. The result lists facts and reasons only. Whether the website shows
 * the record also depends on templates, caches, extensions and the visitor,
 * which this class does not know.
 *
 * @internal
 */
final readonly class VisibilityEvaluator
{
    public const HIDDEN = 'hidden';
    public const SCHEDULED = 'scheduled';
    public const EXPIRED = 'expired';
    public const ACCESS_RESTRICTED = 'accessRestricted';

    public const WORKSPACE_UNCHANGED = 'unchanged';
    public const WORKSPACE_NEW = 'new';
    public const WORKSPACE_CHANGED = 'changed';
    public const WORKSPACE_DELETED = 'deleted';

    /**
     * @param array<string, mixed> $row
     * @param int $now Unix timestamp the settings are evaluated at
     */
    public function evaluate(array $row, EnableFields $fields, int $now): RowVisibility
    {
        $hidden = $fields->hidden !== '' && (bool)($row[$fields->hidden] ?? false);
        $startTime = $fields->startTime !== '' ? max(0, (int)($row[$fields->startTime] ?? 0)) : 0;
        $endTime = $fields->endTime !== '' ? max(0, (int)($row[$fields->endTime] ?? 0)) : 0;
        $groups = $fields->frontendGroups !== '' ? self::parseGroups($row[$fields->frontendGroups] ?? '') : [];

        $reasons = [];
        if ($hidden) {
            $reasons[] = self::HIDDEN;
        }
        // Same comparison as TYPO3's start and end time restrictions
        if ($startTime > $now) {
            $reasons[] = self::SCHEDULED;
        }
        if ($endTime > 0 && $endTime <= $now) {
            $reasons[] = self::EXPIRED;
        }
        if ($groups !== []) {
            $reasons[] = self::ACCESS_RESTRICTED;
        }
        return new RowVisibility($hidden, $startTime, $endTime, $groups, $reasons);
    }

    /**
     * Whether a page passes its restrictions on to its subpages ("Extend to
     * subpages"), which TYPO3 applies to hidden, start and end time and
     * frontend user groups.
     *
     * @param array<string, mixed> $pageRow
     */
    public function restrictsSubpages(array $pageRow, RowVisibility $visibility, string $inheritanceField): bool
    {
        return $inheritanceField !== '' && (bool)($pageRow[$inheritanceField] ?? false) && $visibility->reasons !== [];
    }

    /**
     * The state of a record in the workspace it was read in, from the row as
     * BackendUtility::workspaceOL() returns it: a version carries the UID of
     * the live record and "_ORIG_uid", a record created in the workspace has
     * the "new" version state.
     *
     * @param array<string, mixed> $row
     * @return string|null Null in the live workspace
     */
    public function getWorkspaceState(array $row, int $workspaceId): ?string
    {
        if ($workspaceId === 0) {
            return null;
        }
        $state = VersionState::tryFrom((int)($row['t3ver_state'] ?? 0));
        $versionUid = (int)($row['_ORIG_uid'] ?? 0);
        if ($versionUid > 0 && $versionUid !== (int)($row['uid'] ?? 0)) {
            return $state === VersionState::DELETE_PLACEHOLDER ? self::WORKSPACE_DELETED : self::WORKSPACE_CHANGED;
        }
        if ((int)($row['t3ver_wsid'] ?? 0) === $workspaceId && $state === VersionState::NEW_PLACEHOLDER) {
            return self::WORKSPACE_NEW;
        }
        return self::WORKSPACE_UNCHANGED;
    }

    /**
     * @return list<int>
     */
    private static function parseGroups(mixed $value): array
    {
        if (!is_scalar($value)) {
            return [];
        }
        $groups = [];
        foreach (explode(',', (string)$value) as $part) {
            $part = trim($part);
            if (preg_match('/^-?\d{1,10}$/D', $part) && (int)$part !== 0 && !in_array((int)$part, $groups, true)) {
                $groups[] = (int)$part;
            }
        }
        return $groups;
    }
}
