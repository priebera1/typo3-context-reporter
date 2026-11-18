<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery\Email;

use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Renders the HTML part of the report email from ReportEmailModel with the
 * fixed template EXT:context_reporter/Resources/Private/Templates/Email/Report.html.
 *
 * Gmail clips messages above 102 KB. The technical context is the long part:
 * it is shown as a table while the email stays below the budget, otherwise as
 * compact preformatted text, and left out (pointing to the plain-text part)
 * when even that is too long.
 *
 * @internal
 */
final readonly class ReportEmailHtmlRenderer
{
    public const TEMPLATE = 'EXT:context_reporter/Resources/Private/Templates/Email/Report.html';
    public const BUDGET_BYTES = 90000;

    /**
     * Phone widths and dark mode (Apple Mail, iOS Mail); everything else is inline.
     */
    private const CSS = 'body{margin:0;padding:0;-webkit-text-size-adjust:100%}table{border-collapse:collapse}a{text-underline-offset:2px}'
        . '.v,.tv{word-break:break-word;overflow-wrap:anywhere}'
        . '@media (max-width:520px){.px{padding-left:16px!important;padding-right:16px!important}.outer{padding:0!important}.card{border-left:0!important;border-right:0!important}.h1{font-size:20px!important;line-height:26px!important}.bt{display:table!important;width:100%!important;box-sizing:border-box!important;margin:0 0 8px!important}.b1,.b2{text-align:center!important}.col{display:block!important;width:100%!important;box-sizing:border-box!important;padding:0!important}.cell{display:block!important;width:100%!important;box-sizing:border-box!important;border-left:0!important}}'
        . '@media (prefers-color-scheme:dark){:root .bg-sheet{background:#0F1416!important}:root .bg-card{background:#161D20!important}:root .bg-panel{background:#131A1C!important}:root .bd{border-color:#2A3639!important}:root .t1{color:#E8EDEC!important}:root .t2{color:#B9C4C4!important}:root .t3{color:#8E9C9D!important}:root .lnk{color:#8AD8DE!important}:root .ta{color:#E9B35B!important}:root .mk{background:#E9B35B!important}:root .tag{border-color:#3E4D50!important;color:#B9C4C4!important}:root .b1c{background:#62C6CE!important}:root .b1{background:#62C6CE!important;border-color:#62C6CE!important;color:#0F1416!important}:root .b2c{background:transparent!important}:root .b2{color:#8AD8DE!important;border-color:#8AD8DE!important}}';

    /**
     * Outlook for Windows: 96 dpi, so the layout is not scaled (passed raw, the "o:" tags are not Fluid).
     */
    private const OUTLOOK_HEAD = '<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->';

    public function __construct(
        private ViewFactoryInterface $viewFactory,
    ) {}

    /**
     * @param array<string, mixed> $model ReportEmailModel::create()
     * @param string $technicalText The technical context as text (the compact fallback)
     */
    public function render(array $model, string $technicalText): string
    {
        $html = '';
        foreach (['columns', 'text', 'omitted'] as $mode) {
            $html = $this->renderWith($model, $mode, $technicalText);
            if (strlen($html) <= self::BUDGET_BYTES) {
                return $html;
            }
        }
        return $html;
    }

    /**
     * @param array<string, mixed> $model
     */
    private function renderWith(array $model, string $technicalMode, string $technicalText): string
    {
        $view = $this->viewFactory->create(new ViewFactoryData(templatePathAndFilename: self::TEMPLATE));
        $view->assignMultiple([
            'model' => $model,
            'technicalMode' => $technicalMode,
            'technicalText' => $technicalText,
            'css' => self::CSS,
            'outlookHead' => self::OUTLOOK_HEAD,
        ]);
        return trim($view->render());
    }
}
