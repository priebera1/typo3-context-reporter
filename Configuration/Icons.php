<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Imaging\IconProvider\SvgSpriteIconProvider;
use TYPO3\CMS\Core\Information\Typo3Version;

// TYPO3 14 renders module icons in the text and accent colors of the backend theme;
// TYPO3 13 keeps the colored extension icon
$moduleIcon = (new Typo3Version())->getMajorVersion() >= 14 ? 'module-v14.svg' : 'Extension.svg';

return [
    // Sprite based, so the icon follows the text color (light and dark backend)
    'context-reporter-report' => [
        'provider' => SvgSpriteIconProvider::class,
        'sprite' => 'EXT:context_reporter/Resources/Public/Icons/icons.svg#context-reporter-report',
        'source' => 'EXT:context_reporter/Resources/Public/Icons/report.svg',
    ],
    'module-context-reporter' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:context_reporter/Resources/Public/Icons/' . $moduleIcon,
    ],
];
