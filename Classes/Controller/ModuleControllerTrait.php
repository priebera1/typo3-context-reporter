<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Controller;

use Priebera\ContextReporter\Security\ReportAccessPolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Shared helpers of the System > Context Reports controllers.
 *
 * @property ModuleTemplateFactory $moduleTemplateFactory
 * @property UriBuilder $uriBuilder
 * @property IconFactory $iconFactory
 * @internal
 */
trait ModuleControllerTrait
{
    private function createView(ServerRequestInterface $request): ModuleTemplate
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->translate('title'));
        $view->setModuleClass('context-reporter-module');
        return $view;
    }

    /**
     * @param ButtonBar::BUTTON_POSITION_* $position
     */
    private function addLinkButton(ModuleTemplate $view, string $href, string $label, string $icon, int $group, string $position = ButtonBar::BUTTON_POSITION_LEFT): void
    {
        $button = GeneralUtility::makeInstance(LinkButton::class)
            ->setHref($href)
            ->setTitle($this->translate($label))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon($icon, IconSize::SMALL));
        $view->getDocHeaderComponent()->getButtonBar()->addButton($button, $position, $group);
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function buildModuleUrl(string $action = '', array $parameters = []): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute(ReportModuleController::MODULE . ($action !== '' ? '.' . $action : ''), $parameters);
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function redirectWithMessage(
        ServerRequestInterface $request,
        string $message,
        ContextualFeedbackSeverity $severity,
        string $action = '',
        array $parameters = [],
        string $title = '',
    ): ResponseInterface {
        $this->moduleTemplateFactory->create($request)->addFlashMessage($message, $title, $severity);
        return new RedirectResponse($this->buildModuleUrl($action, $parameters), 303);
    }

    /**
     * The module is restricted to administrators; every action checks it again.
     */
    private function isAdmin(): bool
    {
        $backendUser = $this->getBackendUser();
        return $backendUser !== null && $backendUser->isAdmin();
    }

    private function accessDenied(): ResponseInterface
    {
        return new HtmlResponse(htmlspecialchars($this->translate('message.accessDenied')), 403);
    }

    private function getBackendUserId(): int
    {
        $backendUser = $this->getBackendUser();
        return $backendUser !== null ? ReportAccessPolicy::getUserId($backendUser) : 0;
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }

    private function translate(string $key): string
    {
        return $this->getLanguageService()?->sL('LLL:EXT:context_reporter/Resources/Private/Language/locallang_module.xlf:' . $key) ?: $key;
    }

    private function getLanguageService(): ?LanguageService
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        return $languageService instanceof LanguageService ? $languageService : null;
    }
}
