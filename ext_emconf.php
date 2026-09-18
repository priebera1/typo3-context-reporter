<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Context Reporter - Object-aware support reports',
    'description' => 'Editors report a problem where it happens in the TYPO3 backend. The report automatically carries the TYPO3 context a developer needs: page, record, content type, file or folder, site, language, workspace, module, system and browser details, plus an optional locally annotated screenshot. Delivered by download, email or signed webhook, with a local report history.',
    'category' => 'be',
    'author' => 'Patrik Priebera',
    'author_email' => 'patrik@priebera.sk',
    'author_company' => '',
    'state' => 'beta',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.3.99',
            'php' => '8.2.0-8.4.99',
            'filelist' => '13.4.0-14.3.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'workspaces' => '',
        ],
    ],
];
