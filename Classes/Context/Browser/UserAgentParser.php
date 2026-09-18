<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Context\Browser;

/**
 * Best-effort detection of the common desktop and mobile browsers for the
 * human readable summary. The raw user agent string is always kept as well.
 *
 * @internal
 */
final class UserAgentParser
{
    private const BROWSERS = [
        ['Edge', '~Edg(?:e|A|iOS)?/(\d+)~'],
        ['Opera', '~OPR/(\d+)~'],
        ['Firefox', '~(?:Firefox|FxiOS)/(\d+)~'],
        ['Chrome', '~(?:CriOS|Chrome)/(\d+)~'],
        ['Safari', '~Version/(\d+(?:\.\d+)?).*Safari/~'],
    ];

    /**
     * @return array{name: string, version: string, os: string}
     */
    public function parse(string $userAgent, string $platformHint = ''): array
    {
        $name = '';
        $version = '';
        foreach (self::BROWSERS as [$candidate, $pattern]) {
            if (preg_match($pattern, $userAgent, $matches)) {
                $name = $candidate;
                $version = $matches[1];
                break;
            }
        }

        return ['name' => $name, 'version' => $version, 'os' => $this->detectOperatingSystem($userAgent, $platformHint)];
    }

    private function detectOperatingSystem(string $userAgent, string $platformHint): string
    {
        $platformHint = trim($platformHint);
        if ($platformHint !== '' && preg_match('/^[A-Za-z0-9 ._-]{1,32}$/D', $platformHint)) {
            return $platformHint;
        }
        return match (true) {
            str_contains($userAgent, 'iPad') => 'iPadOS',
            str_contains($userAgent, 'iPhone') => 'iOS',
            (bool)preg_match('/Android (\d+)/', $userAgent, $matches) => 'Android ' . $matches[1],
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Windows NT') => 'Windows',
            str_contains($userAgent, 'Macintosh'), str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => '',
        };
    }
}
