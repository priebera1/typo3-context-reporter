<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Fixtures;

use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Column items of the fixture extension "container_columns": the columns of
 * the backend layout, plus a container column for elements inside a
 * container (here: elements with the header "Container child").
 */
final class ContainerColumns
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function addContainerColumns(array &$parameters): void
    {
        GeneralUtility::makeInstance(BackendLayoutView::class)->colPosListItemProcFunc($parameters);
        if (($parameters['row']['header'] ?? '') === 'Container child') {
            $parameters['items'][] = ['label' => 'Container column', 'value' => 200];
        }
    }
}
