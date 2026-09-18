<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Backend\ToolbarItem;

use Priebera\ContextReporter\Backend\ReporterAssets;
use Priebera\ContextReporter\Configuration\ExtensionInfo;
use Priebera\ContextReporter\Security\ReportAccessPolicy;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;

/**
 * "Report a problem" button in the backend toolbar. Opens the report dialog
 * for whatever the user is currently looking at.
 *
 * @internal
 */
final class ReportToolbarItem implements ToolbarItemInterface, RequestAwareToolbarItemInterface
{
    private ?ServerRequestInterface $request = null;

    public function __construct(
        private readonly ReportAccessPolicy $accessPolicy,
        private readonly BackendViewFactory $backendViewFactory,
        private readonly ReporterAssets $assets,
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function checkAccess(): bool
    {
        return $this->accessPolicy->canReport($GLOBALS['BE_USER'] ?? null);
    }

    public function getItem(): string
    {
        $request = $this->request ?? $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return '';
        }
        $this->assets->addDialogResources();
        return $this->backendViewFactory
            ->create($request, ['typo3/cms-backend', ExtensionInfo::PACKAGE_NAME])
            ->render('ToolbarItems/ReportToolbarItem');
    }

    public function hasDropDown(): bool
    {
        return false;
    }

    public function getDropDown(): string
    {
        return '';
    }

    /**
     * @return array<string, string>
     */
    public function getAdditionalAttributes(): array
    {
        return [];
    }

    public function getIndex(): int
    {
        return 26;
    }
}
