<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Domain;

enum DeliveryStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
