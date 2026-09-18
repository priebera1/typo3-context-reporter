<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Backend\EventListener;

use Priebera\ContextReporter\Backend\ReportTriggerFactory;
use Priebera\ContextReporter\Domain\ReportSource;
use Priebera\ContextReporter\Security\ReportAccessPolicy;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Adds "Report problem" to the document header of the record editing form
 * (FormEngine, route "record_edit") through the Core button bar event.
 *
 * @internal
 */
#[AsEventListener('context-reporter/record-editor-report-button')]
final readonly class AddReportButtonToRecordEditor
{
    public const BUTTON_GROUP = 90;
    private const LABELS = 'LLL:EXT:context_reporter/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private ReportAccessPolicy $accessPolicy,
        private ReportTriggerFactory $triggers,
    ) {}

    public function __invoke(ModifyButtonBarEvent $event): void
    {
        $request = $this->triggers->getRequest($event);
        if ($request === null) {
            return;
        }
        $route = $request->getAttribute('route');
        if (!$route instanceof Route || $route->getOption('_identifier') !== 'record_edit') {
            return;
        }
        $edit = $request->getQueryParams()['edit'] ?? null;
        if (!is_array($edit) || $edit === [] || !$this->accessPolicy->canReport($GLOBALS['BE_USER'] ?? null)) {
            return;
        }

        $target = [];
        $single = $this->findSingleEditedRecord($edit);
        if ($single !== null) {
            $target = [
                'data-context-reporter-table' => $single['table'],
                'data-context-reporter-uid' => (string)$single['uid'],
            ];
        }
        $button = $this->triggers->createDocHeaderButton(
            ReportSource::FormEngine,
            $target,
            $this->getLanguageService()->sL(self::LABELS . 'recordEditor.button'),
            $this->getLanguageService()->sL(self::LABELS . 'recordEditor.button.title'),
            true,
        );
        $this->triggers->addToDocHeader($event, $button, self::BUTTON_GROUP);
    }

    /**
     * The explicit target when exactly one existing record is open.
     *
     * @param array<array-key, mixed> $edit
     * @return array{table: string, uid: int}|null
     */
    private function findSingleEditedRecord(array $edit): ?array
    {
        if (count($edit) !== 1) {
            return null;
        }
        $table = (string)array_key_first($edit);
        $commands = $edit[$table];
        if (!is_array($commands) || count($commands) !== 1) {
            return null;
        }
        $uid = (string)array_key_first($commands);
        if (reset($commands) !== 'edit' || !preg_match('/^[1-9]\d{0,9}$/D', $uid) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $table)) {
            return null;
        }
        return ['table' => $table, 'uid' => (int)$uid];
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
