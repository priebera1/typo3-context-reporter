<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Browser;

/**
 * Browser details are supplied by the reporter's browser and therefore
 * untrusted. Only an allowlist of well-typed, bounded values survives.
 *
 * @internal
 */
final readonly class BrowserInfoSanitizer
{
    private const MAX_DIMENSION = 20000;
    private const LANGUAGE_TAG = '/^[A-Za-z]{2,8}(-[A-Za-z0-9]{1,8}){0,3}$/D';
    private const TIME_ZONE = '~^[A-Za-z0-9_+\-]+(/[A-Za-z0-9_+\-]+){0,2}$~D';

    public function __construct(
        private UserAgentParser $userAgentParser,
    ) {}

    /**
     * @param array<array-key, mixed> $raw
     * @return array<string, mixed>
     */
    public function sanitize(array $raw): array
    {
        $values = [];

        $userAgent = $this->text($raw['userAgent'] ?? null, 512);
        if ($userAgent !== '') {
            $values['userAgent'] = $userAgent;
        }
        $platform = $this->text($raw['platform'] ?? null, 64);
        if ($platform !== '') {
            $values['platform'] = $platform;
        }
        foreach (['mobile', 'touch'] as $flag) {
            if (is_bool($raw[$flag] ?? null)) {
                $values[$flag] = $raw[$flag];
            }
        }
        $language = $this->languageTag($raw['language'] ?? null);
        if ($language !== '') {
            $values['language'] = $language;
        }
        if (is_array($raw['languages'] ?? null)) {
            $languages = array_values(array_filter(array_map($this->languageTag(...), $raw['languages'])));
            if ($languages !== []) {
                $values['languages'] = array_slice($languages, 0, 5);
            }
        }
        $timeZone = $raw['timeZone'] ?? null;
        if (is_string($timeZone) && strlen($timeZone) <= 64 && preg_match(self::TIME_ZONE, $timeZone)) {
            $values['timeZone'] = $timeZone;
        }
        foreach (['viewport', 'screen'] as $key) {
            $dimensions = $this->dimensions($raw[$key] ?? null);
            if ($dimensions !== null) {
                $values[$key] = $dimensions;
            }
        }
        $pixelRatio = $raw['devicePixelRatio'] ?? null;
        if ((is_int($pixelRatio) || is_float($pixelRatio)) && $pixelRatio > 0 && $pixelRatio <= 10) {
            $values['devicePixelRatio'] = round((float)$pixelRatio, 2);
        }
        if (in_array($raw['colorScheme'] ?? null, ['light', 'dark'], true)) {
            $values['colorScheme'] = $raw['colorScheme'];
        }
        if (in_array($raw['backendColorScheme'] ?? null, ['auto', 'light', 'dark'], true)) {
            $values['backendColorScheme'] = $raw['backendColorScheme'];
        }
        if (is_bool($raw['reducedMotion'] ?? null)) {
            $values['reducedMotion'] = $raw['reducedMotion'];
        }

        if ($values === []) {
            return [];
        }

        $detected = array_filter(
            $this->userAgentParser->parse($values['userAgent'] ?? '', $values['platform'] ?? ''),
            static fn(string $value): bool => $value !== '',
        );
        $summary = $this->summarize($detected, $values);

        return array_merge($summary !== '' ? ['summary' => $summary] : [], $detected, $values);
    }

    /**
     * @param array<string, string> $detected
     * @param array<string, mixed> $values
     */
    private function summarize(array $detected, array $values): string
    {
        $parts = [];
        $browser = trim(($detected['name'] ?? '') . ' ' . ($detected['version'] ?? ''));
        if ($browser !== '') {
            $parts[] = $browser;
        }
        if (isset($detected['os'])) {
            $parts[] = $detected['os'];
        }
        if (isset($values['viewport'])) {
            $viewport = $values['viewport']['width'] . '×' . $values['viewport']['height'];
            if (isset($values['devicePixelRatio']) && $values['devicePixelRatio'] !== 1.0) {
                $viewport .= ' @' . rtrim(rtrim(number_format($values['devicePixelRatio'], 2, '.', ''), '0'), '.') . 'x';
            }
            $parts[] = $viewport;
        }
        return implode(' · ', $parts);
    }

    private function text(mixed $value, int $maxLength): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = (string)preg_replace('/[\x00-\x1F\x7F]/', '', mb_scrub($value, 'UTF-8'));
        return mb_substr(trim($value), 0, $maxLength);
    }

    private function languageTag(mixed $value): string
    {
        return is_string($value) && strlen($value) <= 35 && preg_match(self::LANGUAGE_TAG, $value) ? $value : '';
    }

    /**
     * @return array{width: int, height: int}|null
     */
    private function dimensions(mixed $value): ?array
    {
        if (!is_array($value) || !is_numeric($value['width'] ?? null) || !is_numeric($value['height'] ?? null)) {
            return null;
        }
        return [
            'width' => max(0, min(self::MAX_DIMENSION, (int)$value['width'])),
            'height' => max(0, min(self::MAX_DIMENSION, (int)$value['height'])),
        ];
    }
}
