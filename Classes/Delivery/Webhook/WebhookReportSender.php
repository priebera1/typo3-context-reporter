<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery\Webhook;

use Priebera\ContextReporter\Configuration\WebhookSettings;
use Priebera\ContextReporter\Delivery\DeliveryOutcome;
use Priebera\ContextReporter\Delivery\DeliveryRequest;
use Priebera\ContextReporter\Delivery\Http\HttpExchange;
use Priebera\ContextReporter\Delivery\SafeErrorMessage;
use Priebera\ContextReporter\Export\JsonReportExporter;
use Priebera\ContextReporter\Export\ReportPayloadFactory;

/**
 * Posts a report as the signed "report.created" webhook request. The webhook
 * and the Context Reporter Hub destination send exactly this request, each
 * with its own endpoint and secret; they judge the answer differently.
 *
 * @internal
 */
final readonly class WebhookReportSender
{
    public const EVENT = 'report.created';

    public function __construct(
        private WebhookClient $client,
        private ReportPayloadFactory $payloadFactory,
        private JsonReportExporter $jsonExporter,
    ) {}

    /**
     * The generic webhook: delivered with any 2xx answer.
     */
    public function send(WebhookSettings $settings, DeliveryRequest $request): DeliveryOutcome
    {
        $prepared = $this->prepare($settings, $request);
        if ($prepared instanceof DeliveryOutcome) {
            return $prepared;
        }
        return $this->client->post($settings, self::EVENT, $prepared, $this->headers($request));
    }

    /**
     * The request and what came back, for the Hub, which requires a valid
     * acknowledgement. A request that could not be built is a failure outcome.
     */
    public function exchange(WebhookSettings $settings, DeliveryRequest $request): HttpExchange|DeliveryOutcome
    {
        $prepared = $this->prepare($settings, $request);
        if ($prepared instanceof DeliveryOutcome) {
            return $prepared;
        }
        return $this->client->exchange($settings, self::EVENT, $prepared, $this->headers($request));
    }

    private function prepare(WebhookSettings $settings, DeliveryRequest $request): string|DeliveryOutcome
    {
        try {
            $screenshot = $settings->includeScreenshot ? $request->getScreenshotContent() : null;
            return $this->jsonExporter->export([
                'event' => self::EVENT,
                'delivery' => [
                    'id' => $request->deliveryId,
                    'attempt' => $request->attempt,
                    'sentAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ],
                'report' => $this->payloadFactory->create($request->report, $request->reportUrl, $screenshot),
            ], pretty: false);
        } catch (\Throwable $exception) {
            return DeliveryOutcome::failure(SafeErrorMessage::fromThrowable($exception, $settings->getSecrets()));
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(DeliveryRequest $request): array
    {
        return [
            'X-Context-Reporter-Report' => $request->report->identifier,
            'X-Context-Reporter-Delivery' => $request->deliveryId,
            'X-Context-Reporter-Attempt' => (string)$request->attempt,
        ];
    }
}
