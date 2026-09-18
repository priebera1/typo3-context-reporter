<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery\Destination;

use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Priebera\ContextReporter\Delivery\DeliveryOutcome;
use Priebera\ContextReporter\Delivery\DeliveryRequest;
use Priebera\ContextReporter\Delivery\DestinationInterface;
use Priebera\ContextReporter\Delivery\SafeErrorMessage;
use Priebera\ContextReporter\Delivery\Webhook\WebhookClient;
use Priebera\ContextReporter\Export\JsonReportExporter;
use Priebera\ContextReporter\Export\ReportPayloadFactory;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Posts the report as JSON to a configured URL, optionally signed with
 * HMAC-SHA256 and/or authenticated with a configured header. Works with n8n,
 * Make, Zapier and custom endpoints.
 *
 * @internal
 */
#[AsTaggedItem(priority: 10)]
final readonly class WebhookDestination implements DestinationInterface
{
    public const IDENTIFIER = 'webhook';
    public const EVENT = 'report.created';

    public function __construct(
        private ExtensionSettingsProvider $settingsProvider,
        private WebhookClient $client,
        private ReportPayloadFactory $payloadFactory,
        private JsonReportExporter $jsonExporter,
    ) {}

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function isEnabled(): bool
    {
        return $this->settingsProvider->get()->webhook->isUsable();
    }

    public function describeTarget(): string
    {
        return $this->settingsProvider->get()->webhook->getDisplayTarget();
    }

    public function deliver(DeliveryRequest $request): DeliveryOutcome
    {
        $settings = $this->settingsProvider->get()->webhook;
        $report = $request->report;

        try {
            $screenshot = $settings->includeScreenshot ? $request->getScreenshotContent() : null;
            $body = $this->jsonExporter->export([
                'event' => self::EVENT,
                'delivery' => [
                    'id' => $request->deliveryId,
                    'attempt' => $request->attempt,
                    'sentAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ],
                'report' => $this->payloadFactory->create($report, $request->reportUrl, $screenshot),
            ], pretty: false);
        } catch (\Throwable $exception) {
            return DeliveryOutcome::failure(SafeErrorMessage::fromThrowable($exception, $settings->getSecrets()));
        }

        return $this->client->post($settings, self::EVENT, $body, [
            'X-Context-Reporter-Report' => $report->identifier,
            'X-Context-Reporter-Delivery' => $request->deliveryId,
            'X-Context-Reporter-Attempt' => (string)$request->attempt,
        ]);
    }
}
