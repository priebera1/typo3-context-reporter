<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Backend\EventListener;

use Priebera\ContextReporter\Backend\Compatibility\ListActionAdapter;
use Priebera\ContextReporter\Context\ReportTarget;
use Priebera\ContextReporter\Domain\ReportSource;
use Priebera\ContextReporter\Security\ReportAccessPolicy;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\InaccessibleFolder;
use TYPO3\CMS\Filelist\Event\ProcessFileListActionsEvent;

/**
 * Adds a compact "Report problem" action to files and folders in the list
 * view of the file list. It is shown as a primary action through the user
 * TSconfig option options.file_list.primaryActions (Configuration/user.tsconfig).
 *
 * @internal
 */
#[AsEventListener('context-reporter/file-list-report-action')]
final readonly class AddReportActionToFileList
{
    private const LABELS = 'LLL:EXT:context_reporter/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private ReportAccessPolicy $accessPolicy,
        private ListActionAdapter $listActions,
    ) {}

    public function __invoke(ProcessFileListActionsEvent $event): void
    {
        if (!$this->accessPolicy->canReport($GLOBALS['BE_USER'] ?? null)) {
            return;
        }
        $resource = $event->getResource();
        if ($resource instanceof File && (int)$resource->getUid() > 0) {
            $attributes = ['data-context-reporter-table' => ReportTarget::FILE_TABLE, 'data-context-reporter-uid' => (string)$resource->getUid()];
            $label = 'action.reportFile';
        } elseif ($resource instanceof Folder
            && !$resource instanceof InaccessibleFolder
            && ReportTarget::isValidFolderIdentifier($resource->getCombinedIdentifier())
        ) {
            $attributes = ['data-context-reporter-folder' => $resource->getCombinedIdentifier()];
            $label = 'action.reportFolder';
        } else {
            return;
        }

        $this->listActions->addFileListAction(
            $event,
            ReportSource::FileList,
            $attributes,
            $this->getLanguageService()->sL(self::LABELS . $label),
        );
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
