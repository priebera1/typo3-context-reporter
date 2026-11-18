<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Domain;

enum DeliveryStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /**
     * Claimed and being sent; its outcome is recorded right after the
     * dispatch. Still "sending" afterwards means the process ended before the
     * outcome was known: the delivery may have been sent (DeliveryService).
     */
    case Sending = 'sending';
}
