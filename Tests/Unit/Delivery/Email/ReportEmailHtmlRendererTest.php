<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Delivery\Email;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Delivery\Email\ReportEmailHtmlRenderer;
use Priebera\ContextReporter\Tests\Unit\Fixtures\RecordingViewFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Gmail clips emails above 102 KB: the technical context, the long part, gets shorter forms until the HTML fits.
 */
final class ReportEmailHtmlRendererTest extends UnitTestCase
{
    #[Test]
    public function theTechnicalContextIsAShortTableWhileTheEmailFits(): void
    {
        $views = new RecordingViewFactory(static fn(array $variables): string => str_repeat('x', 40000));
        self::assertSame(40000, strlen((new ReportEmailHtmlRenderer($views))->render(['title' => 'T'], 'text')));
        self::assertSame(['columns'], array_column($views->rendered, 'technicalMode'));
        self::assertStringContainsString('@media (prefers-color-scheme:dark)', (string)$views->rendered[0]['css']);
        self::assertStringContainsString('@media (max-width:520px)', (string)$views->rendered[0]['css']);
    }

    #[Test]
    public function aLongTechnicalContextBecomesTextAndIsLeftOutWhenEvenThatIsTooLong(): void
    {
        $sizes = ['columns' => 150000, 'text' => 60000, 'omitted' => 20000];
        $views = new RecordingViewFactory(static fn(array $variables): string => str_repeat('x', $sizes[$variables['technicalMode']]));
        self::assertSame(60000, strlen((new ReportEmailHtmlRenderer($views))->render([], 'technical text')));
        self::assertSame(['columns', 'text'], array_column($views->rendered, 'technicalMode'));
        self::assertSame('technical text', $views->rendered[1]['technicalText']);

        $huge = new RecordingViewFactory(static fn(array $variables): string => str_repeat('x', $variables['technicalMode'] === 'omitted' ? 30000 : 200000));
        self::assertSame(30000, strlen((new ReportEmailHtmlRenderer($huge))->render([], 'technical text')));
        self::assertSame(['columns', 'text', 'omitted'], array_column($huge->rendered, 'technicalMode'));
    }
}
