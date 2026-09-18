<?php

declare(strict_types=1);

use Priebera\ContextReporter\Controller\ReportAjaxController;

/**
 * Backend AJAX routes are protected by the backend session and the route token.
 */
return [
    'context_reporter_prepare' => [
        'path' => '/context-reporter/prepare',
        'target' => ReportAjaxController::class . '::prepareAction',
        'methods' => ['POST'],
    ],
    'context_reporter_submit' => [
        'path' => '/context-reporter/submit',
        'target' => ReportAjaxController::class . '::submitAction',
        'methods' => ['POST'],
    ],
];
