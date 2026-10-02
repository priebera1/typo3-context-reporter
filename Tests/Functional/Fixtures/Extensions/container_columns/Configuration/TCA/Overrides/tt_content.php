<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Replaces the column items like container extensions do for the elements inside a container
$GLOBALS['TCA']['tt_content']['columns']['colPos']['config']['itemsProcFunc']
    = \Priebera\ContextReporter\Tests\Functional\Fixtures\ContainerColumns::class . '->addContainerColumns';
