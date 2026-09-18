<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Location;

/**
 * Where in the backend the reporter was, as reported by the browser and
 * reduced to an allowlist. Every value is untrusted and must be re-validated
 * (existence, permissions) before it ends up in a report.
 *
 * @internal
 */
final readonly class BackendLocation
{
    /**
     * @param list<array{table: string, uid: int, command: 'edit'|'new'}> $editedRecords
     * @param array<string, list<string>> $columnsOnly
     * @param array<string, array<string, string>> $defaultValues
     */
    public function __construct(
        public string $path = '',
        public ?int $pageId = null,
        public array $editedRecords = [],
        public array $columnsOnly = [],
        public array $defaultValues = [],
        public string $listTable = '',
        public string $moduleIdentifier = '',
        public string $activeModuleIdentifier = '',
        public ?int $pageTreeSelection = null,
        /** Combined identifier of the folder shown in the file list ("id" parameter), not yet access checked */
        public string $folderIdentifier = '',
    ) {}

    public function isEmpty(): bool
    {
        return $this->path === ''
            && $this->moduleIdentifier === ''
            && $this->activeModuleIdentifier === ''
            && $this->pageTreeSelection === null;
    }

    /**
     * Scalar parameters that are safe and useful to show in a report.
     *
     * @return array<string, string>
     */
    public function getSafeQueryParameters(): array
    {
        $parameters = [];
        if ($this->pageId !== null) {
            $parameters['id'] = (string)$this->pageId;
        }
        if ($this->listTable !== '') {
            $parameters['table'] = $this->listTable;
        }
        return $parameters;
    }
}
