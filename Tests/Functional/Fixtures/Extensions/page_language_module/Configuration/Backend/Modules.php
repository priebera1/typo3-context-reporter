<?php

declare(strict_types=1);

/**
 * Registered like the "web_edit" module of EXT:visual_editor: a page tree
 * module that keeps the selected languages as a list in its module data.
 * The route is never dispatched in the tests.
 */
return [
    'web_edit' => [
        'parent' => 'web',
        'access' => 'user',
        'path' => '/module/web/edit',
        'labels' => ['title' => 'Page language module'],
        'routes' => [
            '_default' => [
                'target' => 'PageLanguageModule\\Controller::handle',
            ],
        ],
        'moduleData' => [
            'languages' => [0],
        ],
    ],
];
