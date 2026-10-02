<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Presentation;

use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Reading report data defensively (stored reports may come from older
 * versions) and translating the labels of the notices.
 *
 * @internal
 */
trait NoticeTrait
{
    private function label(string $key, LanguageService $languageService): string
    {
        return $languageService->sL('LLL:EXT:context_reporter/Resources/Private/Language/locallang.xlf:' . $key) ?: $key;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function array(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<array<array-key, mixed>>
     */
    private function list(array $data, string $key): array
    {
        return array_values(array_filter($this->array($data, $key), is_array(...)));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        return is_scalar($value) && !is_bool($value) ? (string)$value : '';
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function int(array $data, string $key): int
    {
        $value = $data[$key] ?? 0;
        return is_numeric($value) ? (int)$value : 0;
    }
}
