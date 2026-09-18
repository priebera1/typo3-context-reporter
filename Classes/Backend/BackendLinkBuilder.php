<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Backend;

use TYPO3\CMS\Backend\Routing\UriBuilder;

/**
 * Builds absolute backend links for reports. They are created as "shareable"
 * URLs: without the per-session route token, so the recipient is sent through
 * the login and lands on the right backend view afterwards.
 *
 * @internal
 */
final readonly class BackendLinkBuilder
{
    public const REPORT_MODULE = 'system_contextreports';
    public const FILE_LIST_MODULE = 'media_management';

    public function __construct(
        private UriBuilder $uriBuilder,
    ) {}

    public function editRecord(string $table, int $uid): string
    {
        return $this->build('record_edit', ['edit' => [$table => [$uid => 'edit']]]);
    }

    /**
     * @param array<string, int|string> $parameters
     */
    public function module(string $moduleIdentifier, array $parameters = []): string
    {
        return $this->build($moduleIdentifier, $parameters);
    }

    /**
     * The file list showing the given folder.
     */
    public function fileList(string $combinedFolderIdentifier): string
    {
        return $this->build(self::FILE_LIST_MODULE, ['id' => $combinedFolderIdentifier]);
    }

    public function report(string $reportIdentifier): string
    {
        return $this->build(self::REPORT_MODULE . '.show', ['report' => $reportIdentifier]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function build(string $route, array $parameters): string
    {
        try {
            return (string)$this->uriBuilder->buildUriFromRoute($route, $parameters, UriBuilder::SHAREABLE_URL);
        } catch (\Throwable) {
            return '';
        }
    }
}
