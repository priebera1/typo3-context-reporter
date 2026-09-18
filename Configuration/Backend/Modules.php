<?php

declare(strict_types=1);

use Priebera\ContextReporter\Controller\ReportModuleController;
use Priebera\ContextReporter\Controller\ReportSettingsController;

/**
 * System > Context Reports: report history and settings, administrators only.
 * State-changing routes only accept POST; all routes require the route token.
 */
return [
    ReportModuleController::MODULE => [
        'parent' => 'system',
        'access' => 'admin',
        'workspaces' => '*',
        'path' => '/module/system/context-reports',
        'iconIdentifier' => 'module-context-reporter',
        'labels' => 'LLL:EXT:context_reporter/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => ReportModuleController::class . '::indexAction',
            ],
            'show' => [
                'target' => ReportModuleController::class . '::showAction',
            ],
            'retry' => [
                'target' => ReportModuleController::class . '::retryAction',
                'methods' => ['POST'],
            ],
            'delete' => [
                'target' => ReportModuleController::class . '::deleteAction',
                'methods' => ['POST'],
            ],
            'resolve' => [
                'target' => ReportModuleController::class . '::resolveAction',
                'methods' => ['POST'],
            ],
            'reopen' => [
                'target' => ReportModuleController::class . '::reopenAction',
                'methods' => ['POST'],
            ],
            'settings' => [
                'target' => ReportSettingsController::class . '::settingsAction',
            ],
            'saveSettings' => [
                'target' => ReportSettingsController::class . '::saveSettingsAction',
                'methods' => ['POST'],
            ],
            'testEmail' => [
                'target' => ReportSettingsController::class . '::testEmailAction',
                'methods' => ['POST'],
            ],
            'testWebhook' => [
                'target' => ReportSettingsController::class . '::testWebhookAction',
                'methods' => ['POST'],
            ],
            'cleanup' => [
                'target' => ReportSettingsController::class . '::cleanupAction',
                'methods' => ['POST'],
            ],
        ],
    ],
];
