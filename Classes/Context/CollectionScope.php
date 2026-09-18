<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context;

use Priebera\ContextReporter\Context\Routing\ResolvedRoute;
use Priebera\ContextReporter\Context\Subject\ResolvedSubject;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Input for context collectors.
 *
 * @internal
 */
final readonly class CollectionScope
{
    public function __construct(
        public CollectionRequest $request,
        public ResolvedSubject $subject,
        public ?ResolvedRoute $route,
        public BackendUserAuthentication $backendUser,
        public ServerRequestInterface $httpRequest,
    ) {}
}
