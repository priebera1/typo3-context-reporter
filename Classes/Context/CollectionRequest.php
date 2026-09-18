<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context;

use Priebera\ContextReporter\Context\Location\BackendLocation;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Domain\ReportSource;

/**
 * Everything the report dialog tells the server when it opens. All of it is
 * untrusted input; collectors re-validate before using it.
 *
 * @internal
 */
final readonly class CollectionRequest
{
    /**
     * @param array<array-key, mixed> $browser Raw browser details, sanitized by the browser collector
     */
    public function __construct(
        public ReportSource $source,
        public ReportTarget $target,
        public BackendLocation $location,
        public array $browser = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data, BackendLocationFactory $locationFactory): self
    {
        return new self(
            source: ReportSource::tryFrom(is_string($data['source'] ?? null) ? $data['source'] : '') ?? ReportSource::Toolbar,
            target: ReportTarget::fromArray(is_array($data['target'] ?? null) ? $data['target'] : []),
            location: $locationFactory->fromArray(is_array($data['location'] ?? null) ? $data['location'] : []),
            browser: is_array($data['browser'] ?? null) ? $data['browser'] : [],
        );
    }
}
