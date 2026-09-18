<?php

declare(strict_types=1);

use Priebera\ContextReporter\Controller\ReportDownloadController;

return [
    ReportDownloadController::ROUTE => [
        'path' => '/context-reporter/download',
        'target' => ReportDownloadController::class . '::downloadAction',
        'methods' => ['GET'],
    ],
];
