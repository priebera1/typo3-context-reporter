<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery\Destination;

use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Priebera\ContextReporter\Delivery\DeliveryOutcome;
use Priebera\ContextReporter\Delivery\DeliveryRequest;
use Priebera\ContextReporter\Delivery\DestinationInterface;
use Priebera\ContextReporter\Delivery\Webhook\WebhookReportSender;
use Priebera\ContextReporter\Hub\HubAcknowledgement;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Sends the report to a site in Context Reporter Hub. The Hub receives the
 * signed webhook request unchanged; it answers with the reference and link of
 * the report in its inbox, which the delivery history shows like a ticket. A
 * delivery counts as received only with that acknowledgement.
 *
 * Independent of the webhook destination, so both can be used at the same time.
 *
 * @internal
 */
#[AsTaggedItem(priority: 30)]
final readonly class HubDestination implements DestinationInterface
{
    public const IDENTIFIER = 'hub';

    public function __construct(
        private ExtensionSettingsProvider $settingsProvider,
        private WebhookReportSender $sender,
    ) {}

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    /**
     * The Hub rejects unsigned deliveries, so the secret is required.
     */
    public function isEnabled(): bool
    {
        $hub = $this->settingsProvider->get()->hub;
        return $hub->isUsable() && $hub->secret !== '';
    }

    public function describeTarget(): string
    {
        return $this->settingsProvider->get()->hub->getDisplayTarget();
    }

    /**
     * Received only with the Hub's acknowledgement (a reference); see HubAcknowledgement.
     */
    public function deliver(DeliveryRequest $request): DeliveryOutcome
    {
        $hub = $this->settingsProvider->get()->hub;
        $exchange = $this->sender->exchange($hub, $request);
        return $exchange instanceof DeliveryOutcome ? $exchange : HubAcknowledgement::outcome($exchange, $hub);
    }
}
