<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Backend;

use Priebera\ContextReporter\Domain\ReportSource;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\GenericButton;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Creates the "Report problem" triggers of the direct entry points (record
 * list, file list, document headers). All of them carry the entry point and
 * the object as data attributes and are handled by report-trigger.js.
 *
 * @internal
 */
final class ReportTriggerFactory
{
    public const ACTION_NAME = 'contextReporterReport';
    public const ICON = 'context-reporter-report';

    private ?string $iconMarkup = null;

    public function __construct(
        private readonly IconFactory $iconFactory,
        private readonly ReporterAssets $assets,
    ) {}

    /**
     * A compact icon button for the action column of a list, as HTML.
     * TYPO3 v13 list actions are HTML strings; v14 uses createActionButton().
     *
     * @param array<string, string> $targetAttributes data-context-reporter-* attributes of the object
     */
    public function renderActionButton(ReportSource $source, array $targetAttributes, string $label, string $classes = 'btn btn-default'): string
    {
        $this->assets->addTrigger();
        $attributes = [
            'type' => 'button',
            'class' => $classes,
            'title' => $label,
            'aria-label' => $label,
            'data-context-reporter-trigger' => $source->value,
        ] + $targetAttributes;
        $this->iconMarkup ??= $this->iconFactory->getIcon(self::ICON, IconSize::SMALL)->render();
        return '<button ' . GeneralUtility::implodeAttributes($attributes, true) . '>' . $this->iconMarkup . '</button>';
    }

    /**
     * The same compact icon button as a Button API component, as the list
     * actions of TYPO3 v14 expect it. The list renders it as an icon button in
     * the primary group and as a labelled item in the "More options" menu, so
     * the label is set and only its text output is suppressed.
     *
     * @param array<string, string> $targetAttributes data-context-reporter-* attributes of the object
     */
    public function createActionButton(ReportSource $source, array $targetAttributes, string $label): GenericButton
    {
        $this->assets->addTrigger();
        $attributes = [
            'type' => 'button',
            'aria-label' => $label,
            'data-context-reporter-trigger' => $source->value,
        ] + $targetAttributes;
        return GeneralUtility::makeInstance(GenericButton::class)
            ->setTag('button')
            ->setLabel($label)
            ->setTitle($label)
            ->setIcon($this->iconFactory->getIcon(self::ICON, IconSize::SMALL))
            ->setShowLabelText(false)
            ->setAttributes($attributes);
    }

    /**
     * @param array<string, string> $targetAttributes data-context-reporter-* attributes of the object
     */
    public function createDocHeaderButton(ReportSource $source, array $targetAttributes, string $label, string $title, bool $showLabel): GenericButton
    {
        $attributes = ['type' => 'button'];
        if (!$showLabel) {
            $attributes['aria-label'] = $label;
        }
        $attributes['data-context-reporter-trigger'] = $source->value;
        return GeneralUtility::makeInstance(GenericButton::class)
            ->setTag('button')
            ->setLabel($label)
            ->setTitle($title)
            ->setIcon($this->iconFactory->getIcon(self::ICON, IconSize::SMALL))
            ->setShowLabelText($showLabel)
            ->setAttributes($attributes + $targetAttributes);
    }

    /**
     * Adds the button to the right side of the document header, unless a
     * report button is there already. Groups stay sorted: the Core sorts them
     * before the event is dispatched.
     */
    public function addToDocHeader(ModifyButtonBarEvent $event, GenericButton $button, int $group): void
    {
        $buttons = $event->getButtons();
        foreach ($buttons[ButtonBar::BUTTON_POSITION_RIGHT] ?? [] as $existingGroup) {
            foreach ($existingGroup as $existingButton) {
                if ($existingButton instanceof GenericButton && isset($existingButton->getAttributes()['data-context-reporter-trigger'])) {
                    return;
                }
            }
        }
        $buttons[ButtonBar::BUTTON_POSITION_RIGHT][$group][] = $button;
        ksort($buttons[ButtonBar::BUTTON_POSITION_RIGHT]);
        $event->setButtons($buttons);
        $this->assets->addTrigger();
    }

    /**
     * TYPO3 v14 passes the request with the event, v13 only provides it globally.
     * Both branches are analysed, so the check is reported as redundant by
     * PHPStan on one of them (see Build/phpstan.neon).
     */
    public function getRequest(ModifyButtonBarEvent $event): ?ServerRequestInterface
    {
        if (method_exists($event, 'getRequest')) {
            $request = $event->getRequest();
        } else {
            $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        }
        return $request instanceof ServerRequestInterface ? $request : null;
    }
}
