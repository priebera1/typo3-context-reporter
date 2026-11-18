<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery\Destination;

use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Priebera\ContextReporter\Configuration\HubIntake;
use Priebera\ContextReporter\Delivery\DeliveryOutcome;
use Priebera\ContextReporter\Delivery\DeliveryRequest;
use Priebera\ContextReporter\Delivery\DestinationInterface;
use Priebera\ContextReporter\Delivery\Webhook\WebhookReportSender;
use Priebera\ContextReporter\Hub\HubAcknowledgement;
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
    public const EVENT = WebhookReportSender::EVENT;

    public function __construct(
        private ExtensionSettingsProvider $settingsProvider,
        private WebhookReportSender $sender,
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

    /**
     * Any 2xx answer is a delivery, except for a webhook URL that is a Context
     * Reporter Hub intake address (/in/s_…, how 0.3 connected the Hub): that
     * delivery counts only with the Hub's acknowledgement, like the Hub
     * destination (a proxy or login page answering 200 confirms nothing), and
     * keeps what the Hub answered about the screenshot.
     */
    public function deliver(DeliveryRequest $request): DeliveryOutcome
    {
        $webhook = $this->settingsProvider->get()->webhook;
        if (!HubIntake::isIntakeUrl($webhook->url)) {
            return $this->sender->send($webhook, $request);
        }
        $exchange = $this->sender->exchange($webhook, $request);
        return $exchange instanceof DeliveryOutcome ? $exchange : HubAcknowledgement::outcome($exchange, $webhook);
    }
}
