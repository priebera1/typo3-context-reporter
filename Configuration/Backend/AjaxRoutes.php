<?php

declare(strict_types=1);

use Priebera\ContextReporter\Controller\ReportAjaxController;
use Priebera\ContextReporter\Controller\UpdateNoticeAjaxController;

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
    // Hides the update notice of one release for the current administrator
    'context_reporter_update_notice_dismiss' => [
        'path' => '/context-reporter/update-notice/dismiss',
        'target' => UpdateNoticeAjaxController::class . '::dismissAction',
        'methods' => ['POST'],
    ],
];
