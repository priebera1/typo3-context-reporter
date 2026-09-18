<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery;

/**
 * A place reports are pushed to (email, webhook, later a hosted relay).
 *
 * Destinations must not throw: every problem is returned as a failed
 * outcome with a message that is safe to store and display (no secrets,
 * no full URLs). They are registered by implementing this interface.
 *
 * @internal Not yet a public extension point; the interface may change until
 *           the delivery model has settled.
 */
interface DestinationInterface
{
    /**
     * Stable identifier, stored in the delivery audit trail (e.g. "webhook").
     */
    public function getIdentifier(): string;

    /**
     * Whether the destination is enabled and completely configured.
     */
    public function isEnabled(): bool;

    /**
     * Non-secret description of where reports go, e.g. "2 recipients" or
     * "https://hooks.example.com".
     */
    public function describeTarget(): string;

    public function deliver(DeliveryRequest $request): DeliveryOutcome;
}
