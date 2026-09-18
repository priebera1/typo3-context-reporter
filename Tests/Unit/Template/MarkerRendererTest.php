<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Template\MarkerRenderer;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class MarkerRendererTest extends UnitTestCase
{
    #[Test]
    public function knownMarkersAreReplaced(): void
    {
        $result = (new MarkerRenderer())->render(
            'Report {report.id}: {report.title} on {page.title}',
            ['report.id' => 'CR-1234-5678-9ABC', 'report.title' => 'Image missing', 'page.title' => 'Home'],
        );

        self::assertSame('Report CR-1234-5678-9ABC: Image missing on Home', $result);
    }

    #[Test]
    public function unknownMarkersAreLeftUntouchedSoTyposStayVisible(): void
    {
        $result = (new MarkerRenderer())->render('{report.titel} / {nomarker} / {report.id}', ['report.id' => 'X']);

        self::assertSame('{report.titel} / {nomarker} / X', $result);
    }

    #[Test]
    public function knownMarkersWithoutValueRenderEmpty(): void
    {
        $result = (new MarkerRenderer())->render('[{record.uid}]', ['record.uid' => '']);

        self::assertSame('[]', $result);
    }

    #[Test]
    public function markersInsideInsertedValuesAreNotExpandedAgain(): void
    {
        $result = (new MarkerRenderer())->render(
            '{report.title}',
            ['report.title' => 'Look at {reporter.email}', 'reporter.email' => 'secret@example.com'],
        );

        self::assertSame('Look at {reporter.email}', $result);
    }

    #[Test]
    public function singleLineRenderingCollapsesLineBreaksAndControlCharacters(): void
    {
        $result = (new MarkerRenderer())->render(
            "[{project.name}]\n{report.title}",
            ['project.name' => 'Portal', 'report.title' => "Broken\r\nBcc: attacker@example.com\t\x07"],
            singleLine: true,
        );

        self::assertSame('[Portal] Broken Bcc: attacker@example.com', $result);
    }

    #[Test]
    public function multiLineRenderingKeepsLineBreaksButNormalizesThem(): void
    {
        $result = (new MarkerRenderer())->render("A\r\n{report.description}", ['report.description' => "line 1\r\nline 2\x00"]);

        self::assertSame("A\nline 1\nline 2", $result);
    }
}
