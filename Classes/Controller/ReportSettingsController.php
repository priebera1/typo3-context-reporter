<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Controller;

use Priebera\ContextReporter\Backend\BackendUserNames;
use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Priebera\ContextReporter\Configuration\SettingsRepository;
use Priebera\ContextReporter\Configuration\SystemConfiguration;
use Priebera\ContextReporter\Delivery\Email\MailTransportDescription;
use Priebera\ContextReporter\Export\MarkdownReportExporter;
use Priebera\ContextReporter\Retention\RetentionService;
use Priebera\ContextReporter\Settings\DeliveryStatusProvider;
use Priebera\ContextReporter\Settings\DestinationTester;
use Priebera\ContextReporter\Settings\SettingsFieldBuilder;
use Priebera\ContextReporter\Settings\SettingsFormProcessor;
use Priebera\ContextReporter\Settings\StatusMessage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * System > Context Reports > Settings (administrators only).
 *
 * Settings are stored in the TYPO3 registry; values of the TYPO3 system
 * configuration take precedence and are shown read-only. Secrets are
 * write-only.
 *
 * @internal
 */
#[AsController]
final readonly class ReportSettingsController
{
    use ModuleControllerTrait;

    public const DEFAULT_TAB = 'general';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private UriBuilder $uriBuilder,
        private IconFactory $iconFactory,
        private LanguageServiceFactory $languageServiceFactory,
        private ExtensionSettingsProvider $settingsProvider,
        private SettingsRepository $settingsRepository,
        private SystemConfiguration $systemConfiguration,
        private SettingsFormProcessor $formProcessor,
        private SettingsFieldBuilder $fieldBuilder,
        private DeliveryStatusProvider $statusProvider,
        private DestinationTester $tester,
        private RetentionService $retention,
        private BackendUserNames $userNames,
    ) {}

    public function settingsAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->accessDenied();
        }
        return $this->renderSettings($request, $this->getTab($request->getQueryParams()['tab'] ?? null));
    }

    public function saveSettingsAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->accessDenied();
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $tab = $this->getTab($body['tab'] ?? null);
        $input = is_array($body['settings'] ?? null) ? $body['settings'] : [];
        $pinned = $this->systemConfiguration->getOverriddenKeys($tab);

        // Values of the system configuration take part in the validation, e.g. "enabled" requires recipients
        $effective = $this->settingsProvider->getRawConfiguration()[$tab] ?? [];
        foreach ($pinned as $key) {
            $value = is_array($effective) ? ($effective[$key] ?? null) : null;
            if (is_scalar($value)) {
                $input[$key] = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
            }
        }

        $result = $this->formProcessor->process($tab, $input, $this->settingsRepository->load()[$tab] ?? [], $pinned);
        if (!$result->isValid()) {
            return $this->renderSettings($request, $tab, $input, $result->errors);
        }
        $this->settingsRepository->saveSection($tab, $result->values, $this->getBackendUserId());
        $this->settingsProvider->reset();
        return $this->redirectWithMessage($request, $this->translate('settings.saved'), ContextualFeedbackSeverity::OK, 'settings', ['tab' => $tab]);
    }

    public function testEmailAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->accessDenied();
        }
        $status = $this->statusProvider->getEmailStatus();
        $outcome = $this->tester->sendTestEmail();
        if (!$outcome->successful) {
            return $this->redirectWithMessage($request, $outcome->message, ContextualFeedbackSeverity::ERROR, 'settings', ['tab' => 'email'], $this->translate('settings.test.emailFailed'));
        }
        return $this->redirectWithMessage(
            $request,
            sprintf($this->translate('settings.test.emailSucceededDetail'), implode(', ', $status->recipients)),
            $this->hasWarnings($status->messages) ? ContextualFeedbackSeverity::WARNING : ContextualFeedbackSeverity::OK,
            'settings',
            ['tab' => 'email'],
            $this->translate('settings.test.emailSucceeded'),
        );
    }

    public function testWebhookAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->accessDenied();
        }
        $outcome = $this->tester->sendTestWebhook();
        return $outcome->successful
            ? $this->redirectWithMessage($request, sprintf($this->translate('settings.test.webhookSucceededDetail'), $outcome->message), ContextualFeedbackSeverity::OK, 'settings', ['tab' => 'webhook'], $this->translate('settings.test.webhookSucceeded'))
            : $this->redirectWithMessage($request, $outcome->message, ContextualFeedbackSeverity::ERROR, 'settings', ['tab' => 'webhook'], $this->translate('settings.test.webhookFailed'));
    }

    public function cleanupAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->accessDenied();
        }
        $body = $request->getParsedBody();
        $days = is_array($body) && is_string($body['days'] ?? null) && ctype_digit($body['days']) ? (int)$body['days'] : -1;
        // The confirmed preview must belong to the saved retention
        if ($days !== $this->retention->getConfiguredDays()) {
            return $this->redirectWithMessage($request, $this->translate('settings.cleanup.changed'), ContextualFeedbackSeverity::WARNING, 'settings', ['tab' => 'reporting']);
        }
        $result = $this->retention->cleanup($days);
        return $this->redirectWithMessage(
            $request,
            sprintf($this->translate('settings.cleanup.done'), $result->reports, $result->orphanedAttachments + $result->orphanedDeliveries),
            ContextualFeedbackSeverity::OK,
            'settings',
            ['tab' => 'reporting'],
        );
    }

    /**
     * @param array<array-key, mixed>|null $submitted
     * @param array<string, string> $errors
     */
    private function renderSettings(ServerRequestInterface $request, string $tab, ?array $submitted = null, array $errors = []): ResponseInterface
    {
        $languageService = $this->getLanguageService() ?? $this->languageServiceFactory->create('en');
        $view = $this->createView($request);
        $this->addLinkButton($view, $this->buildModuleUrl(), 'button.reports', 'actions-view-go-back', 1);
        if ($errors !== []) {
            $message = $this->translate('settings.invalid');
            if (in_array($tab, ['webhook'], true)) {
                $message .= ' ' . $this->translate('settings.invalidSecrets');
            }
            $view->addFlashMessage($message, '', ContextualFeedbackSeverity::ERROR);
        }

        $tabs = [];
        foreach (SettingsFieldBuilder::getSections() as $section) {
            $tabs[] = [
                'id' => $section,
                'label' => $this->translate('settings.tab.' . $section),
                'url' => $this->buildModuleUrl('settings', ['tab' => $section]),
                'active' => $section === $tab,
            ];
        }

        $view->assignMultiple([
            'activeTab' => $tab,
            'tabs' => $tabs,
            'fields' => $this->fieldBuilder->build($tab, $languageService, $submitted, $errors),
            'saveUrl' => $this->buildModuleUrl('saveSettings'),
            'lastChange' => $this->describeLastChange(),
            'legacyConfiguration' => $this->systemConfiguration->hasLegacyExtensionConfiguration(),
            'overriddenPaths' => implode(', ', $this->systemConfiguration->getOverriddenPaths()),
        ]);
        match ($tab) {
            'email' => $view->assignMultiple($this->getEmailData()),
            'webhook' => $view->assignMultiple($this->getWebhookData()),
            'reporting' => $view->assignMultiple($this->getStorageData()),
            default => null,
        };
        return $view->renderResponse('ReportModule/Settings');
    }

    /**
     * @return array<string, mixed>
     */
    private function getEmailData(): array
    {
        $status = $this->statusProvider->getEmailStatus();
        $attachments = array_keys(array_filter(['screenshot' => $status->attachScreenshot, 'json' => $status->attachJson]));
        return [
            'status' => $this->describeStatus($status->enabled, $status->isReady(), $status->messages),
            'email' => $status,
            'sender' => $status->senderFromTypo3
                ? sprintf($this->translate('settings.status.email.senderTypo3'), $this->formatAddress($status->senderAddress, $status->senderName))
                : $this->formatAddress($status->senderAddress, $status->senderName),
            'transport' => $this->describeTransport($status->transport),
            'attachments' => $attachments !== []
                ? implode(', ', array_map(fn(string $attachment): string => $this->translate('settings.status.attachment.' . $attachment), $attachments))
                : $this->translate('settings.status.attachment.none'),
            'canTest' => $status->canSendTest(),
            'testUrl' => $this->buildModuleUrl('testEmail'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getWebhookData(): array
    {
        $status = $this->statusProvider->getWebhookStatus();
        $state = fn(string $state, string $variable): string => sprintf($this->translate('settings.secret.state.' . $state), $variable);
        $endpoint = $state($status->endpoint->value, $status->endpointVariable);
        if ($status->endpointTarget !== '') {
            $endpoint = $status->endpointTarget . ($status->endpointHasPath ? '/…' : '');
        }
        return [
            'status' => $this->describeStatus($status->enabled, $status->isReady(), $status->messages),
            'webhook' => $status,
            'endpoint' => $endpoint,
            'signature' => $state($status->signature->value, $status->signatureVariable),
            'authentication' => $status->authHeaderName !== ''
                ? $status->authHeaderName . ': ' . $state($status->authHeaderValue->value, $status->authHeaderValueVariable)
                : $state($status->authHeaderValue->value, $status->authHeaderValueVariable),
            'options' => sprintf(
                $this->translate('settings.status.webhook.optionsValue'),
                $status->timeout,
                $this->translate($status->includeScreenshot ? 'settings.status.included' : 'settings.status.notIncluded'),
            ),
            'canTest' => $status->canSendTest(),
            'testUrl' => $this->buildModuleUrl('testWebhook'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getStorageData(): array
    {
        $statistics = $this->retention->getStatistics();
        $preview = $this->retention->preview();
        $orphans = $preview->orphanedAttachments + $preview->orphanedDeliveries;
        $cutoff = $preview->cutoff?->format('Y-m-d H:i') ?? '';
        return [
            'statistics' => $statistics,
            'screenshotSize' => MarkdownReportExporter::formatBytes($statistics->screenshotBytes),
            'preview' => $preview,
            'previewSize' => MarkdownReportExporter::formatBytes($preview->screenshotBytes),
            'previewCutoff' => $cutoff,
            'previewOrphans' => $orphans,
            'cleanupUrl' => $this->buildModuleUrl('cleanup'),
            'cleanupConfirmation' => $preview->reports > 0
                ? sprintf($this->translate('settings.cleanup.confirm.message'), $preview->reports, $cutoff)
                : $this->translate('settings.cleanup.confirm.messageOrphans'),
        ];
    }

    /**
     * @param list<StatusMessage> $messages
     * @return array{label: string, class: string, messages: list<array{severity: string, text: string}>}
     */
    private function describeStatus(bool $enabled, bool $ready, array $messages): array
    {
        return [
            'label' => $this->translate(match (true) {
                $ready => 'settings.status.ready',
                !$enabled => 'settings.status.off',
                default => 'settings.status.notReady',
            }),
            'class' => match (true) {
                $ready => 'success',
                !$enabled => 'default',
                default => 'danger',
            },
            'messages' => array_map(fn(StatusMessage $message): array => [
                'severity' => $message->severity,
                'text' => vsprintf($this->translate('settings.status.' . $message->key), $message->arguments),
            ], $messages),
        ];
    }

    private function describeTransport(MailTransportDescription $transport): string
    {
        $label = sprintf($this->translate('settings.status.transport.' . $transport->type), $transport->target);
        $details = [];
        if ($transport->authentication) {
            $details[] = $this->translate('settings.status.transport.authentication');
        }
        if ($transport->encrypted) {
            $details[] = $this->translate('settings.status.transport.encrypted');
        }
        return $label . ($details !== [] ? ' (' . implode(', ', $details) . ')' : '');
    }

    /**
     * @param list<StatusMessage> $messages
     */
    private function hasWarnings(array $messages): bool
    {
        foreach ($messages as $message) {
            if ($message->severity !== StatusMessage::INFO && $message->key !== 'email.disabled') {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{time?: \DateTimeImmutable, user?: string}
     */
    private function describeLastChange(): array
    {
        $change = $this->settingsRepository->getLastChange();
        $result = [];
        if (isset($change['time'])) {
            $result['time'] = (new \DateTimeImmutable('@' . $change['time']))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        }
        if (isset($change['user']) && $change['user'] > 0) {
            $result['user'] = $this->userNames->resolve([$change['user']])[$change['user']]['label'] ?? '';
        }
        return $result;
    }

    private function formatAddress(string $address, string $name): string
    {
        return $name !== '' ? $name . ' <' . $address . '>' : $address;
    }

    private function getTab(mixed $tab): string
    {
        return is_string($tab) && in_array($tab, SettingsFieldBuilder::getSections(), true) ? $tab : self::DEFAULT_TAB;
    }
}
