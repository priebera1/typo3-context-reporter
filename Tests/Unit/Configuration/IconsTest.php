<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class IconsTest extends UnitTestCase
{
    private const ICON_DIRECTORY = __DIR__ . '/../../../Resources/Public/Icons/';

    #[Test]
    public function moduleIconMatchesTheBackendOfTheRunningTypo3Version(): void
    {
        $icons = require __DIR__ . '/../../../Configuration/Icons.php';
        $source = $icons['module-context-reporter']['source'];

        // TYPO3 14 renders module icons monochrome; TYPO3 13 keeps the colored extension icon
        $expected = (new Typo3Version())->getMajorVersion() >= 14 ? 'module-v14.svg' : 'Extension.svg';
        self::assertSame('EXT:context_reporter/Resources/Public/Icons/' . $expected, $source);
        self::assertFileExists(self::ICON_DIRECTORY . $expected);
    }

    #[Test]
    public function typo3FourteenModuleIconFollowsTheBackendTheme(): void
    {
        $svg = (string)file_get_contents(self::ICON_DIRECTORY . 'module-v14.svg');

        preg_match_all('/\b(?:fill|stroke|color|stop-color)="([^"]*)"/', $svg, $matches);
        self::assertNotSame([], $matches[1]);
        foreach ($matches[1] as $paint) {
            // Like the icons of TYPO3 14: the text color and the accent color of the backend theme
            self::assertContains($paint, ['currentColor', 'var(--icon-color-accent, #ff8700)']);
        }
        self::assertStringNotContainsString('style=', $svg);
        self::assertStringNotContainsString('M0 0h64v64H0z', $svg, 'No background square');
    }
}
