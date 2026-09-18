<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Controller;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;

/**
 * Requests for the routes of System > Context Reports, as the backend
 * router would dispatch them.
 */
trait ModuleRequestTrait
{
    /**
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body A POST request when given
     */
    private function createModuleRequest(string $routeIdentifier, array $query = [], ?array $body = null): ServerRequestInterface
    {
        $router = $this->get(Router::class);
        $method = $body === null ? 'GET' : 'POST';
        $request = $this->createBackendRequest('/typo3' . $router->getRoute($routeIdentifier)?->getPath(), $method);
        $route = $router->matchResult($request)->getRoute();
        self::assertInstanceOf(Route::class, $route);
        self::assertSame($routeIdentifier, $route->getOption('_identifier'));
        $request = $request
            ->withQueryParams($query)
            ->withAttribute('route', $route)
            ->withAttribute('module', $route->getOption('module'));
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $GLOBALS['TYPO3_REQUEST'] = $request;
        return $request;
    }

    /**
     * @return list<array{title: string, message: string, severity: string}>
     */
    private function getFlashMessages(): array
    {
        $queue = $this->get(FlashMessageService::class)->getMessageQueueByIdentifier('core.template.flashMessages');
        return array_values(array_map(static fn(FlashMessage $message): array => [
            'title' => $message->getTitle(),
            'message' => $message->getMessage(),
            'severity' => $message->getSeverity()->name,
        ], $queue->getAllMessagesAndFlush()));
    }
}
