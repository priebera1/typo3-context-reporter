<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery;

/**
 * @internal
 */
final readonly class DestinationRegistry
{
    /**
     * @var array<string, DestinationInterface>
     */
    private array $destinations;

    /**
     * @param iterable<DestinationInterface> $destinations
     */
    public function __construct(iterable $destinations)
    {
        $indexed = [];
        foreach ($destinations as $destination) {
            $indexed[$destination->getIdentifier()] = $destination;
        }
        $this->destinations = $indexed;
    }

    /**
     * @return list<DestinationInterface>
     */
    public function getEnabled(): array
    {
        return array_values(array_filter(
            $this->destinations,
            static fn(DestinationInterface $destination): bool => $destination->isEnabled(),
        ));
    }

    public function get(string $identifier): ?DestinationInterface
    {
        return $this->destinations[$identifier] ?? null;
    }
}
