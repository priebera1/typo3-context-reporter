<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Subject;

use Priebera\ContextReporter\Context\CollectionScope;
use Priebera\ContextReporter\Context\Tca\TcaInspector;
use Priebera\ContextReporter\Domain\SubjectType;

/**
 * The language the reporter was working in: the language of the record, or
 * the language selected in the Page module.
 *
 * @internal
 */
final readonly class SubjectLanguageResolver
{
    private const PAGE_MODULE = 'web_layout';

    public function __construct(
        private TcaInspector $tca,
    ) {}

    public function resolve(CollectionScope $scope): ?int
    {
        $subject = $scope->subject;
        if ($subject->type === SubjectType::Record) {
            $field = $this->tca->getLanguageField($subject->table);
            if ($field === '') {
                return null;
            }
            if ($subject->isNewRecord) {
                $default = $scope->request->location->defaultValues[$subject->table][$field] ?? '0';
                return preg_match('/^-?\d{1,5}$/D', $default) ? (int)$default : 0;
            }
            $value = $subject->record[$field] ?? null;
            return is_numeric($value) ? (int)$value : null;
        }

        if (!$subject->hasPage()) {
            return null;
        }
        if ($this->isPageModule($scope)) {
            $moduleData = $scope->backendUser->getModuleData(self::PAGE_MODULE);
            $language = is_array($moduleData) ? ($moduleData['language'] ?? null) : null;
            if (is_numeric($language)) {
                return (int)$language;
            }
        }
        return $subject->type === SubjectType::Page ? 0 : null;
    }

    private function isPageModule(CollectionScope $scope): bool
    {
        $location = $scope->request->location;
        if ($scope->route !== null && $scope->route->moduleIdentifier !== '') {
            return $scope->route->moduleIdentifier === self::PAGE_MODULE;
        }
        return $location->moduleIdentifier === self::PAGE_MODULE;
    }
}
