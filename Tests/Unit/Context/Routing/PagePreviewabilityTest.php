<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Context\Routing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\ContextReporter\Context\Routing\PagePreviewability;

/**
 * The rule of the "View" button of TYPO3 13.4.0 to 13.4.14, which have no
 * PreviewUriBuilder::isPreviewable() yet.
 */
final class PagePreviewabilityTest extends TestCase
{
    /**
     * @return iterable<string, array{int, array<array-key, mixed>, bool}>
     */
    public static function doktypeProvider(): iterable
    {
        yield 'standard page' => [1, [], true];
        yield 'external link' => [3, [], true];
        yield 'shortcut' => [4, [], true];
        yield 'mount point' => [7, [], true];
        yield 'custom page type above 200' => [201, [], true];
        yield 'spacer' => [199, [], false];
        yield 'folder' => [254, [], false];
        yield 'invalid page type' => [0, [], false];
        yield 'page type disabled in TSconfig' => [1, ['disableButtonForDokType' => '1, 199'], false];
        yield 'TSconfig replaces the defaults' => [254, ['disableButtonForDokType' => '1'], true];
        yield 'empty TSconfig enables all page types' => [254, ['disableButtonForDokType' => ''], true];
    }

    /**
     * @param array<array-key, mixed> $previewTsConfig
     */
    #[Test]
    #[DataProvider('doktypeProvider')]
    public function followsTheViewButtonOfTypo3v13(int $doktype, array $previewTsConfig, bool $expected): void
    {
        self::assertSame($expected, PagePreviewability::isPreviewableDoktype($doktype, $previewTsConfig));
    }
}
