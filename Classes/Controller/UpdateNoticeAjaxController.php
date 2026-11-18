<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Controller;

use Priebera\ContextReporter\UpdateNotice\SemanticVersion;
use Priebera\ContextReporter\UpdateNotice\UpdateNoticeDismissalStore;
use Priebera\ContextReporter\UpdateNotice\UpdateNoticeService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Hides the update notice of one release for the current administrator. The
 * route is POST-only and needs the backend route token TYPO3 adds to every
 * AJAX URL; only a user who sees update notices may dismiss one. The request
 * names the release version, nothing else, and changes only the user's own
 * configuration.
 *
 * @internal
 */
#[AsController]
final readonly class UpdateNoticeAjaxController
{
    public function __construct(
        private UpdateNoticeService $updateNoticeService,
        private UpdateNoticeDismissalStore $dismissalStore,
    ) {}

    public function dismissAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || !$this->updateNoticeService->canSeeUpdateNotices($backendUser)) {
            return $this->json(['success' => false, 'code' => 'accessDenied'], 403);
        }
        if ($request->getMethod() !== 'POST') {
            return $this->json(['success' => false, 'code' => 'methodNotAllowed'], 405);
        }
        $body = $request->getParsedBody();
        $version = SemanticVersion::tryParse(is_array($body) && is_string($body['version'] ?? null) ? $body['version'] : '');
        if ($version === null) {
            return $this->json(['success' => false, 'code' => 'invalidVersion'], 400);
        }
        $this->dismissalStore->dismiss($backendUser, $version);
        return $this->json(['success' => true, 'version' => $version->toString()]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): ResponseInterface
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }
}
