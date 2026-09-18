<?php

declare(strict_types=1);

return [
    'dependencies' => [
        'backend',
        'core',
    ],
    'tags' => [
        // The context menu imports the callback module on demand
        'backend.contextmenu',
    ],
    'imports' => [
        '@priebera/context-reporter/' => 'EXT:context_reporter/Resources/Public/JavaScript/',
    ],
];
