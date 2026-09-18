<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Settings;

use Priebera\ContextReporter\Configuration\EnvironmentReference;
use Priebera\ContextReporter\Configuration\ExtensionSettingsFactory;
use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Priebera\ContextReporter\Delivery\Email\MailConfigurationInspector;

/**
 * Explains whether email and webhook deliveries can work with the saved
 * configuration and why not. Secrets are only described by their state.
 *
 * @internal
 */
final readonly class DeliveryStatusProvider
{
    public function __construct(
        private ExtensionSettingsProvider $settingsProvider,
        private MailConfigurationInspector $mailConfiguration,
    ) {}

    public function getEmailStatus(): EmailStatus
    {
        $settings = $this->settingsProvider->get()->email;
        $raw = $this->getRawSection('email');
        $transport = $this->mailConfiguration->describe();

        $messages = [];
        if (!$settings->enabled) {
            $messages[] = new StatusMessage(StatusMessage::WARNING, 'email.disabled');
        }
        if ($settings->recipients === []) {
            $messages[] = new StatusMessage(StatusMessage::DANGER, 'email.noRecipients');
        }
        match ($transport->type) {
            'null' => $messages[] = new StatusMessage(StatusMessage::DANGER, 'email.nullTransport'),
            'mbox' => $messages[] = new StatusMessage(StatusMessage::WARNING, 'email.mbox'),
            default => null,
        };
        if ($transport->spool !== '') {
            $messages[] = new StatusMessage(StatusMessage::WARNING, 'email.spool', [$transport->spool]);
        }
        if ($transport->localCatcher) {
            $messages[] = new StatusMessage(StatusMessage::INFO, $transport->ddev ? 'email.ddev' : 'email.localCatcher');
        }

        $configuredSender = $this->string($raw, 'senderAddress');
        $senderFromTypo3 = $settings->senderAddress === '';
        if ($configuredSender !== '' && $senderFromTypo3) {
            $messages[] = new StatusMessage(StatusMessage::WARNING, 'email.senderInvalid', [$transport->defaultSenderAddress]);
        } elseif ($senderFromTypo3 && !$transport->defaultSenderConfigured) {
            $messages[] = new StatusMessage(StatusMessage::WARNING, 'email.defaultSender', [$transport->defaultSenderAddress]);
        }

        return new EmailStatus(
            enabled: $settings->enabled,
            recipients: $settings->recipients,
            senderAddress: $senderFromTypo3 ? $transport->defaultSenderAddress : $settings->senderAddress,
            senderName: $senderFromTypo3 ? $transport->defaultSenderName : $settings->senderName,
            senderFromTypo3: $senderFromTypo3,
            attachScreenshot: $settings->attachScreenshot,
            attachJson: $settings->attachJson,
            replyToReporter: $settings->replyToReporter,
            transport: $transport,
            messages: $messages,
        );
    }

    public function getWebhookStatus(): WebhookStatus
    {
        $settings = $this->settingsProvider->get()->webhook;
        $raw = $this->getRawSection('webhook');

        $rawUrl = $this->string($raw, 'url');
        $endpoint = SecretState::of($rawUrl, $settings->url);
        $signature = SecretState::of($this->string($raw, 'secret'), $settings->secret);
        $rawHeaderName = $this->string($raw, 'authHeaderName');
        $authHeaderValue = SecretState::of($this->string($raw, 'authHeaderValue'), $settings->authHeaderValue);
        if ($authHeaderValue !== SecretState::NotConfigured && $rawHeaderName !== '' && !preg_match(ExtensionSettingsFactory::HEADER_NAME_PATTERN, $rawHeaderName)) {
            $authHeaderValue = SecretState::Invalid;
        }
        $endpointVariable = $this->variable($raw, 'url');
        $signatureVariable = $this->variable($raw, 'secret');
        $authHeaderValueVariable = $this->variable($raw, 'authHeaderValue');

        $messages = [];
        if (!$settings->enabled) {
            $messages[] = new StatusMessage(StatusMessage::WARNING, 'webhook.disabled');
        }
        match ($endpoint) {
            SecretState::NotConfigured => $messages[] = new StatusMessage(StatusMessage::DANGER, 'webhook.noEndpoint'),
            SecretState::EnvironmentUnavailable => $messages[] = new StatusMessage(StatusMessage::DANGER, 'webhook.endpointEnvironment', [$endpointVariable]),
            SecretState::Invalid => $messages[] = new StatusMessage(StatusMessage::DANGER, 'webhook.endpointInvalid'),
            default => null,
        };
        if ($endpoint->isUsable() && strtolower((string)parse_url($settings->url, PHP_URL_SCHEME)) === 'http') {
            $messages[] = new StatusMessage(StatusMessage::WARNING, 'webhook.insecure');
        }
        match ($authHeaderValue) {
            SecretState::EnvironmentUnavailable => $messages[] = new StatusMessage(StatusMessage::DANGER, 'webhook.authEnvironment', [$authHeaderValueVariable]),
            SecretState::Invalid => $messages[] = new StatusMessage(StatusMessage::DANGER, 'webhook.authInvalid'),
            default => null,
        };
        if ($signature === SecretState::EnvironmentUnavailable) {
            $messages[] = new StatusMessage(StatusMessage::DANGER, 'webhook.secretEnvironment', [$signatureVariable]);
        } elseif ($signature === SecretState::NotConfigured && $endpoint !== SecretState::NotConfigured) {
            $messages[] = new StatusMessage(StatusMessage::INFO, 'webhook.unsigned');
        }

        $path = $endpoint === SecretState::Configured ? parse_url($settings->url) : null;
        return new WebhookStatus(
            enabled: $settings->enabled,
            endpoint: $endpoint,
            endpointVariable: $endpointVariable,
            endpointTarget: $endpoint === SecretState::Configured ? $settings->getDisplayTarget() : '',
            endpointHasPath: is_array($path) && (!in_array($path['path'] ?? '', ['', '/'], true) || isset($path['query'])),
            signature: $signature,
            signatureVariable: $signatureVariable,
            authHeaderName: $settings->authHeaderName,
            authHeaderValue: $authHeaderValue,
            authHeaderValueVariable: $authHeaderValueVariable,
            timeout: $settings->timeoutSeconds,
            includeScreenshot: $settings->includeScreenshot,
            allowInsecureHttp: $settings->allowInsecureHttp,
            messages: $messages,
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function getRawSection(string $section): array
    {
        $raw = $this->settingsProvider->getRawConfiguration()[$section] ?? null;
        return is_array($raw) ? $raw : [];
    }

    /**
     * @param array<array-key, mixed> $section
     */
    private function variable(array $section, string $key): string
    {
        return EnvironmentReference::getVariableName($this->string($section, $key)) ?? '';
    }

    /**
     * @param array<array-key, mixed> $section
     */
    private function string(array $section, string $key): string
    {
        $value = $section[$key] ?? '';
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
