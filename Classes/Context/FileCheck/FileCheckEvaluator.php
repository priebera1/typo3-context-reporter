<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\FileCheck;

use TYPO3\CMS\Core\Resource\File;

/**
 * Problems of a file as TYPO3 knows them. A referenced file is judged by
 * the file index only (missing flag, recorded size) and the state of its
 * storage; the storage is not asked for the file. Only a reported file is
 * also looked up in its storage.
 *
 * @internal
 */
final readonly class FileCheckEvaluator
{
    /** TYPO3 marks the file as missing */
    public const MISSING = 'missing';
    /** The storage does not have the reported file (look-up) */
    public const NOT_IN_STORAGE = 'notInStorage';
    /** The storage is offline in the backend */
    public const STORAGE_OFFLINE = 'storageOffline';
    /** The file has 0 bytes */
    public const EMPTY = 'empty';
    /** The file reference is hidden */
    public const HIDDEN = 'hidden';
    /** The file reference points to a file the file index does not know (any more) */
    public const BROKEN_REFERENCE = 'brokenReference';

    public const STORAGE_CHECK_FOUND = 'found';
    public const STORAGE_CHECK_NOT_FOUND = 'notFound';
    public const STORAGE_CHECK_NOT_CHECKED = 'notChecked';

    /**
     * @return list<string>
     */
    public function checkIndexedFile(File $file): array
    {
        $problems = [];
        $missing = $file->isMissing();
        if ($missing) {
            $problems[] = self::MISSING;
        }
        if (!$file->getStorage()->isOnline()) {
            $problems[] = self::STORAGE_OFFLINE;
        }
        if (!$missing && self::getIndexedSize($file) === 0) {
            $problems[] = self::EMPTY;
        }
        return $problems;
    }

    /**
     * The reported file: the index facts and whether its storage has the
     * file. An offline storage is not asked.
     *
     * @return array{storageCheck: string, problems: list<string>}
     */
    public function checkReportedFile(File $file): array
    {
        $online = $file->getStorage()->isOnline();
        $storageCheck = self::STORAGE_CHECK_NOT_CHECKED;
        if ($online) {
            try {
                $storageCheck = $file->exists() ? self::STORAGE_CHECK_FOUND : self::STORAGE_CHECK_NOT_FOUND;
            } catch (\Throwable) {
                // e.g. no permission to read folders or a driver error
            }
        }

        $problems = [];
        $missing = $file->isMissing();
        if ($missing) {
            $problems[] = self::MISSING;
        }
        if ($storageCheck === self::STORAGE_CHECK_NOT_FOUND) {
            $problems[] = self::NOT_IN_STORAGE;
        }
        if (!$online) {
            $problems[] = self::STORAGE_OFFLINE;
        }
        if (!$missing && $storageCheck !== self::STORAGE_CHECK_NOT_FOUND && $this->getReportedSize($file, $storageCheck) === 0) {
            $problems[] = self::EMPTY;
        }
        return ['storageCheck' => $storageCheck, 'problems' => $problems];
    }

    /**
     * The size in the file index. File::getSize() would ask the storage
     * when the index has no size (0).
     */
    private static function getIndexedSize(File $file): ?int
    {
        $size = $file->getProperty('size');
        return is_numeric($size) ? (int)$size : null;
    }

    private function getReportedSize(File $file, string $storageCheck): ?int
    {
        $size = self::getIndexedSize($file);
        if ($size === 0 && $storageCheck === self::STORAGE_CHECK_FOUND) {
            try {
                // The index may not know the size yet
                return $file->getSize();
            } catch (\Throwable) {
                return $size;
            }
        }
        return $size;
    }
}
