<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Hub;

/**
 * The optional details of the Hub's acknowledgement of a report:
 *
 *     "screenshots": {"received": 2, "stored": 1, "omitted": 1, "reasons": ["storage_full"]},
 *     "storage": "ok"
 *
 * The report itself was received (it has a reference); these keys only say
 * whether the Hub stored the screenshot and how full the storage of its
 * workspace is. Older Hubs do not send them.
 *
 * The values come from the answer of a remote system: they are read strictly
 * (exact types, known codes, bounded counts and lists), are only ever shown as
 * text, and never decide anything else. Unknown reasons become "other",
 * unknown storage states are ignored.
 *
 * @internal
 */
final readonly class HubReceipt
{
    public const REASON_STORAGE_FULL = 'storage_full';
    public const REASON_TOO_LARGE = 'too_large';
    public const REASON_UNSUPPORTED = 'unsupported';
    public const REASON_CHECKSUM_MISMATCH = 'checksum_mismatch';
    public const REASON_NOT_SAVED = 'not_saved';
    /**
     * Any reason this version does not know
     */
    public const REASON_OTHER = 'other';

    public const REASONS = [
        self::REASON_STORAGE_FULL,
        self::REASON_TOO_LARGE,
        self::REASON_UNSUPPORTED,
        self::REASON_CHECKSUM_MISMATCH,
        self::REASON_NOT_SAVED,
    ];

    public const STORAGE_OK = 'ok';
    public const STORAGE_NEARLY_FULL = 'nearly_full';
    public const STORAGE_CRITICAL = 'critical';
    public const STORAGE_FULL = 'full';

    public const STORAGE_STATES = [
        self::STORAGE_OK,
        self::STORAGE_NEARLY_FULL,
        self::STORAGE_CRITICAL,
        self::STORAGE_FULL,
    ];

    /**
     * Counts above this are clamped: a report has at most a few attachments
     */
    public const MAX_COUNT = 100;

    /**
     * Only this many entries of "reasons" are read
     */
    public const MAX_REASONS = 10;

    /**
     * @param list<string> $reasons Known codes or REASON_OTHER, without duplicates
     */
    private function __construct(
        public ?int $screenshotsReceived,
        public ?int $screenshotsStored,
        public ?int $screenshotsOmitted,
        public array $reasons,
        public ?string $storage,
    ) {}

    /**
     * Reads the optional keys of a decoded acknowledgement. Null when it has
     * none that can be used (an older Hub, or only malformed values).
     *
     * @param array<mixed> $answer
     */
    public static function fromAnswer(array $answer): ?self
    {
        $received = $stored = $omitted = null;
        $reasons = [];
        $screenshots = $answer['screenshots'] ?? null;
        if (is_array($screenshots) && !array_is_list($screenshots)) {
            $received = self::count($screenshots['received'] ?? null);
            $stored = self::count($screenshots['stored'] ?? null);
            $omitted = self::count($screenshots['omitted'] ?? null);
            $reasons = self::reasons($screenshots['reasons'] ?? null);
        }
        $storage = $answer['storage'] ?? null;
        $storage = is_string($storage) && in_array($storage, self::STORAGE_STATES, true) ? $storage : null;

        $receipt = new self($received, $stored, $omitted, $reasons, $storage);
        return $receipt->isEmpty() ? null : $receipt;
    }

    /**
     * Reads what toJson() stored, through the same strict rules.
     */
    public static function fromJson(string $json): ?self
    {
        if ($json === '' || strlen($json) > 4096) {
            return null;
        }
        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($data) ? self::fromAnswer($data) : null;
    }

    public function toJson(): string
    {
        $data = [];
        if ($this->hasScreenshotFacts()) {
            $data['screenshots'] = array_filter([
                'received' => $this->screenshotsReceived,
                'stored' => $this->screenshotsStored,
                'omitted' => $this->screenshotsOmitted,
            ], static fn(?int $value): bool => $value !== null) + ['reasons' => $this->reasons];
        }
        if ($this->storage !== null) {
            $data['storage'] = $this->storage;
        }
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * How many screenshots the Hub received but did not store. Without a
     * usable "omitted", the difference of "received" and "stored".
     */
    public function getOmittedScreenshots(): int
    {
        if ($this->screenshotsOmitted !== null) {
            return $this->screenshotsOmitted;
        }
        if ($this->screenshotsReceived !== null && $this->screenshotsStored !== null) {
            return max(0, $this->screenshotsReceived - $this->screenshotsStored);
        }
        return 0;
    }

    public function hasOmittedScreenshots(): bool
    {
        return $this->getOmittedScreenshots() > 0;
    }

    /**
     * The reason to name: the first known one the Hub gave, else "other".
     */
    public function getPrimaryReason(): string
    {
        foreach ($this->reasons as $reason) {
            if ($reason !== self::REASON_OTHER) {
                return $reason;
            }
        }
        return self::REASON_OTHER;
    }

    /**
     * The storage of the Hub workspace is at least 90 % used.
     */
    public function isStorageCritical(): bool
    {
        return $this->storage === self::STORAGE_CRITICAL || $this->storage === self::STORAGE_FULL;
    }

    private function hasScreenshotFacts(): bool
    {
        return $this->screenshotsReceived !== null || $this->screenshotsStored !== null
            || $this->screenshotsOmitted !== null || $this->reasons !== [];
    }

    private function isEmpty(): bool
    {
        return !$this->hasScreenshotFacts() && $this->storage === null;
    }

    /**
     * A JSON integer of at least 0; no floats, numeric strings or booleans.
     */
    private static function count(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? min($value, self::MAX_COUNT) : null;
    }

    /**
     * @return list<string>
     */
    private static function reasons(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        $reasons = [];
        foreach (array_slice($value, 0, self::MAX_REASONS) as $reason) {
            $reasons[] = is_string($reason) && in_array($reason, self::REASONS, true) ? $reason : self::REASON_OTHER;
        }
        return array_values(array_unique($reasons));
    }
}
