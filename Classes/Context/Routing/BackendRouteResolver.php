<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Routing;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Routing\Router;

/**
 * Maps the path of the backend document the reporter was looking at to a
 * registered backend route, using the Core router.
 *
 * @internal
 */
final readonly class BackendRouteResolver
{
    public function __construct(
        private Router $router,
    ) {}

    public function resolve(string $path, ServerRequestInterface $request): ?ResolvedRoute
    {
        if ($path === '') {
            return null;
        }
        $probe = $request
            ->withMethod('GET')
            ->withUri($request->getUri()->withPath($path)->withQuery('')->withFragment(''))
            ->withQueryParams([])
            ->withParsedBody(null);
        try {
            $route = $this->router->matchResult($probe)->getRoute();
        } catch (\Throwable) {
            return null;
        }
        $identifier = $route->getOption('_identifier');
        if (!is_string($identifier) || $identifier === '') {
            return null;
        }
        $module = $route->getOption('module');
        return new ResolvedRoute(
            identifier: $identifier,
            path: $route->getPath(),
            moduleIdentifier: $module instanceof ModuleInterface ? $module->getIdentifier() : '',
        );
    }
}
