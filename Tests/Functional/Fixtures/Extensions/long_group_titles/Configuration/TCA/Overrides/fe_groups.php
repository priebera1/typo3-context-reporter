<?php

declare(strict_types=1);

defined('TYPO3') or die();

// The database column follows "max", so titles up to 255 characters can be stored
$GLOBALS['TCA']['fe_groups']['columns']['title']['config']['max'] = 255;
