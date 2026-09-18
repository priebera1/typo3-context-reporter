<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Backend;

use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Adds the report dialog assets (JavaScript, styles and labels) to the
 * current backend document, once per request.
 *
 * @internal
 */
final class ReporterAssets
{
    public const LABEL_FILE = 'EXT:context_reporter/Resources/Private/Language/locallang_js.xlf';
    /** Notifications of core's <typo3-copy-to-clipboard>, used by the dialog */
    public const COPY_LABEL_FILE = 'EXT:backend/Resources/Private/Language/locallang_copytoclipboard.xlf';
    public const STYLESHEET = 'EXT:context_reporter/Resources/Public/Css/context-reporter.css';
    public const TRIGGER_MODULE = '@priebera/context-reporter/report-trigger.js';

    private bool $triggerAdded = false;

    public function __construct(
        private readonly PageRenderer $pageRenderer,
    ) {}

    /**
     * Labels and styles of the dialog; the toolbar item template loads the modules.
     */
    public function addDialogResources(): void
    {
        $this->pageRenderer->addInlineLanguageLabelFile(self::LABEL_FILE);
        $this->pageRenderer->addInlineLanguageLabelFile(self::COPY_LABEL_FILE);
        $this->pageRenderer->addCssFile(self::STYLESHEET);
    }

    /**
     * Everything a document with report trigger buttons needs.
     */
    public function addTrigger(): void
    {
        if ($this->triggerAdded) {
            return;
        }
        $this->triggerAdded = true;
        $this->addDialogResources();
        // Module instructions are not de-duplicated by the page renderer
        $this->pageRenderer->loadJavaScriptModule(self::TRIGGER_MODULE);
    }
}
