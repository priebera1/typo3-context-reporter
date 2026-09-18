<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery;

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
     */
    public function __construct(
        public readonly Report $report,
        public readonly string $reportUrl,
        public readonly int $attempt,
        public readonly string $deliveryId,
        private readonly \Closure $screenshotLoader,
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
