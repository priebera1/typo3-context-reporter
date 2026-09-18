<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Configuration;

/**
 * Which facts about the reporting backend user may leave TYPO3 with a report.
 *
 * @internal
 */
final readonly class ReporterPrivacySettings
{
    public function __construct(
        public bool $includeUid,
        public bool $includeUsername,
        public bool $includeRealName,
        public bool $includeEmail,
        public bool $includeGroups,
    ) {}

    public function sharesAnything(): bool
    {
        return $this->includeUid
            || $this->includeUsername
            || $this->includeRealName
            || $this->includeEmail
            || $this->includeGroups;
    }
}
