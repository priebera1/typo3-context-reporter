<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Collector;

use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\ContextCollectorInterface;
use Priebera\ContextReporter\Context\Routing\FrontendAddressResolver;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The facts behind the website address of the reported page, or of the page
 * of a reported record ("page.frontendUrl"): no site, page type without
 * "View", workspace state, language and translation, fallback languages,
 * site base without host, failed address. Nothing when there is nothing to
 * explain. These are stored settings, not what visitors get.
 *
 * @internal
 */
#[AsTaggedItem(priority: 45)]
final readonly class RoutingCollector implements ContextCollectorInterface
{
    public function __construct(
        private FrontendAddressResolver $addressResolver,
    ) {}

    public function getSectionKey(): string
    {
        return 'routing';
    }

    public function collect(CollectionScope $scope): array
    {
        $address = $this->addressResolver->resolve($scope);
        if ($address === null) {
            return [];
        }
        $data = [];
        if ($address->notes !== []) {
            $data['notes'] = $address->notes;
        }
        if ($address->fallback !== null) {
            $data['fallback'] = $address->fallback;
        }
        return $data;
    }
}
