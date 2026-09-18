<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Routing;

/**
 * @internal
 */
final readonly class ResolvedRoute
{
    public const RECORD_EDIT = 'record_edit';

    public function __construct(
        public string $identifier,
        public string $path,
        public string $moduleIdentifier = '',
    ) {}

    public function isRecordEditing(): bool
    {
        return $this->identifier === self::RECORD_EDIT;
    }
}
