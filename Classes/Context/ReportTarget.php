<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context;

use Priebera\ContextReporter\Domain\SubjectType;

/**
 * The object a report was explicitly started for (context menu, record list,
 * editing form, file list). Generic toolbar reports have no explicit target.
 *
 * Pages and records are identified by table and UID, files by their sys_file
 * UID and folders by their combined identifier ("<storage UID>:<path>").
 *
 * @internal
 */
final readonly class ReportTarget
{
    public const FILE_TABLE = 'sys_file';

    private const TABLE_NAME = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/D';
    private const MAX_FOLDER_IDENTIFIER_LENGTH = 1024;

    private function __construct(
        public SubjectType $type,
        public string $table = '',
        public int $uid = 0,
        public string $identifier = '',
    ) {}

    public static function none(): self
    {
        return new self(SubjectType::Backend);
    }

    public static function page(int $uid): self
    {
        return self::record('pages', $uid);
    }

    public static function record(string $table, int $uid): self
    {
        if (!preg_match(self::TABLE_NAME, $table) || $uid <= 0) {
            throw new \InvalidArgumentException('Invalid report target.', 1757930201);
        }
        return new self($table === 'pages' ? SubjectType::Page : SubjectType::Record, $table, $uid);
    }

    public static function file(int $uid): self
    {
        if ($uid <= 0) {
            throw new \InvalidArgumentException('Invalid report target.', 1757930203);
        }
        return new self(SubjectType::File, self::FILE_TABLE, $uid);
    }

    public static function folder(string $identifier): self
    {
        if (!self::isValidFolderIdentifier($identifier)) {
            throw new \InvalidArgumentException('Invalid report target.', 1757930204);
        }
        return new self(SubjectType::Folder, identifier: $identifier);
    }

    /**
     * A combined folder identifier of a real storage: storage UID greater
     * than 0 (0 is the legacy fallback storage), an absolute path, valid
     * UTF-8 and no control characters.
     */
    public static function isValidFolderIdentifier(string $identifier): bool
    {
        return strlen($identifier) <= self::MAX_FOLDER_IDENTIFIER_LENGTH
            && preg_match('/^[1-9]\d{0,9}:\/[^\x00-\x1F\x7F]*$/uD', $identifier) === 1;
    }

    /**
     * @param array<array-key, mixed> $data
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $type = SubjectType::tryFrom(is_string($data['type'] ?? null) ? $data['type'] : '') ?? SubjectType::Backend;
        if ($type === SubjectType::Backend) {
            return self::none();
        }
        if ($type === SubjectType::Folder) {
            $identifier = $data['identifier'] ?? null;
            return self::folder(is_string($identifier) ? $identifier : '');
        }
        $uid = $data['uid'] ?? null;
        $uid = is_int($uid) ? $uid : (is_string($uid) && preg_match('/^\d{1,10}$/D', $uid) ? (int)$uid : 0);
        if ($type === SubjectType::File) {
            return self::file($uid);
        }
        $table = $type === SubjectType::Page ? 'pages' : ($data['table'] ?? '');
        if (!is_string($table)) {
            throw new \InvalidArgumentException('Invalid report target.', 1757930202);
        }
        return self::record($table, $uid);
    }

    public function isExplicit(): bool
    {
        return $this->type !== SubjectType::Backend;
    }
}
