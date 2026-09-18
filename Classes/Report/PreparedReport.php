<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Report;

use Priebera\ContextReporter\Domain\ContextDocument;
use Priebera\ContextReporter\Domain\ReportSource;

/**
 * The collected context as shown in the report dialog, sealed in a draft token.
 *
 * @internal
 */
final readonly class PreparedReport
{
    public function __construct(
        public ReportSource $source,
        public ContextDocument $document,
        public string $draftToken,
    ) {}
}
