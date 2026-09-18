<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Domain;

/**
 * Whether an administrator has dealt with a report. Independent of the
 * delivery state; new reports are open.
 */
enum ReviewState: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
