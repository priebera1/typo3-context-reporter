<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Subject;

use Priebera\ContextReporter\Context\ReportTarget;
use Priebera\ContextReporter\Domain\SubjectType;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;

/**
 * The access checked object a report is about. The database rows and file
 * objects are for the collectors only; they never leave the server as a whole.
 *
 * @internal
 */
final readonly class ResolvedSubject
{
    /**
     * @param array<string, mixed> $record Workspace overlaid record row
     * @param array<string, mixed> $page Workspace overlaid row of the page the subject is or lives on
     */
    public function __construct(
        public SubjectType $type,
        public string $table = '',
        public int $uid = 0,
        public array $record = [],
        public int $pageUid = 0,
        public array $page = [],
        public bool $isNewRecord = false,
        public ?File $file = null,
        public ?Folder $folder = null,
    ) {}

    /**
     * @param array<string, mixed> $page
     */
    public static function backend(int $pageUid = 0, array $page = []): self
    {
        return new self(SubjectType::Backend, pageUid: $pageUid, page: $page);
    }

    public static function file(File $file): self
    {
        return new self(SubjectType::File, ReportTarget::FILE_TABLE, (int)$file->getUid(), file: $file);
    }

    public static function folder(Folder $folder): self
    {
        return new self(SubjectType::Folder, folder: $folder);
    }

    public function hasPage(): bool
    {
        return $this->pageUid > 0 && $this->page !== [];
    }
}
