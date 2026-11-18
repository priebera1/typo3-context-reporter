<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery;

use Priebera\ContextReporter\Domain\DeliveryAttempt;
use Priebera\ContextReporter\Domain\Report;

/**
 * @internal
 */
final class DeliveryRequest
{
    private ?string $screenshotContent = null;
    private bool $screenshotLoaded = false;

    /**
     * @param \Closure(): ?string $screenshotLoader
     * @param array<string, DeliveryAttempt> $priorAttempts The latest earlier attempt per destination, e.g. so the
     *        email can name the reference of the Hub, which is delivered first (destinations stay independent)
     */
    public function __construct(
        public readonly Report $report,
        public readonly string $reportUrl,
        public readonly int $attempt,
        public readonly string $deliveryId,
        private readonly \Closure $screenshotLoader,
        public readonly array $priorAttempts = [],
    ) {}

    public function getScreenshotContent(): ?string
    {
        if ($this->report->screenshot === null) {
            return null;
        }
        if (!$this->screenshotLoaded) {
            $this->screenshotContent = ($this->screenshotLoader)();
            $this->screenshotLoaded = true;
        }
        return $this->screenshotContent;
    }
}
