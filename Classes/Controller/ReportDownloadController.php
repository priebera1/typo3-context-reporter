<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Controller;

use Priebera\ContextReporter\Backend\BackendLinkBuilder;
use Priebera\ContextReporter\Delivery\DeliveryService;
use Priebera\ContextReporter\Domain\Repository\ReportRepository;
use Priebera\ContextReporter\Export\JsonReportExporter;
use Priebera\ContextReporter\Export\MarkdownReportExporter;
use Priebera\ContextReporter\Export\ReportPayloadFactory;
use Priebera\ContextReporter\Report\ReportIdGenerator;
use Priebera\ContextReporter\Security\ReportAccessPolicy;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Serves a stored report as JSON (screenshot embedded), as Markdown, or its
 * screenshot. Administrators may download every report, reporters their own.
 *
 * @internal
 */
#[AsController]
final readonly class ReportDownloadController
{
    public const ROUTE = 'context_reporter_download';

    public function __construct(
        private ReportRepository $reports,
        private ReportAccessPolicy $accessPolicy,
        private ReportPayloadFactory $payloadFactory,
        private JsonReportExporter $jsonExporter,
        private MarkdownReportExporter $markdownExporter,
        private DeliveryService $deliveryService,
        private BackendLinkBuilder $links,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function downloadAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $identifier = $request->getQueryParams()['report'] ?? '';
        $format = $request->getQueryParams()['format'] ?? 'json';
        if (!$backendUser instanceof BackendUserAuthentication || !is_string($identifier) || !ReportIdGenerator::isValid($identifier)) {
            return $this->responseFactory->createResponse(404);
        }
        $report = $this->reports->findByIdentifier($identifier);
        if ($report === null || !$this->accessPolicy->canAccessReport($backendUser, $report)) {
            return $this->responseFactory->createResponse(404);
        }
        $userId = ReportAccessPolicy::getUserId($backendUser);

        switch ($format) {
            case 'json':
                $payload = $this->payloadFactory->create($report, $this->links->report($report->identifier), $this->reports->findScreenshotContent($report->uid));
                $this->deliveryService->recordDownload($report, $userId, 'JSON');
                return $this->createFileResponse($this->jsonExporter->export($payload), 'application/json; charset=utf-8', $report->identifier . '.json', true);
            case 'markdown':
                $payload = $this->payloadFactory->create($report, $this->links->report($report->identifier));
                $this->deliveryService->recordDownload($report, $userId, 'Markdown');
                return $this->createFileResponse($this->markdownExporter->export($payload), 'text/markdown; charset=utf-8', $report->identifier . '.md', true);
            case 'screenshot':
                $content = $report->screenshot !== null ? $this->reports->findScreenshotContent($report->uid) : null;
                if ($content === null || $report->screenshot === null) {
                    return $this->responseFactory->createResponse(404);
                }
                $asAttachment = ($request->getQueryParams()['download'] ?? '') === '1';
                return $this->createFileResponse($content, $report->screenshot->mediaType, $report->screenshot->filename, $asAttachment);
            default:
                return $this->responseFactory->createResponse(400);
        }
    }

    private function createFileResponse(string $content, string $contentType, string $filename, bool $asAttachment): ResponseInterface
    {
        // File names are built from validated report identifiers only
        $disposition = ($asAttachment ? 'attachment' : 'inline') . '; filename="' . $filename . '"';
        return $this->responseFactory->createResponse()
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Length', (string)strlen($content))
            ->withHeader('Content-Disposition', $disposition)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox")
            ->withBody($this->streamFactory->createStream($content));
    }
}
