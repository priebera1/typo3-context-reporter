<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context;

/**
 * A collector contributes one section of allowlisted, JSON compatible data to
 * the context document of a report.
 *
 * Collectors must only use objects that went through the subject resolver
 * (or are otherwise access checked), must never copy whole database rows and
 * return an empty array when they have nothing to contribute.
 *
 * Collectors are registered by implementing this interface and ordered with
 * the "priority" attribute of #[AsTaggedItem] (higher runs first).
 *
 * @internal Not yet a public extension point; the interface may change until
 *           the collector model has settled.
 */
interface ContextCollectorInterface
{
    /**
     * Section name. "project", "reporter", "system" and "browser" are top-level
     * sections of the report; all other names end up below "context".
     */
    public function getSectionKey(): string;

    /**
     * @return array<string, mixed>
     */
    public function collect(CollectionScope $scope): array;
}
