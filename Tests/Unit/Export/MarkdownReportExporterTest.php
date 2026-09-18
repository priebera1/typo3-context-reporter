<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\ExtensionInfo;
use Priebera\ContextReporter\Export\ContextDetailsFormatter;
use Priebera\ContextReporter\Export\MarkdownReportExporter;
use Priebera\ContextReporter\Export\ReportPayloadFactory;
use Priebera\ContextReporter\Tests\Unit\Fixtures\ReportFixture;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class MarkdownReportExporterTest extends UnitTestCase
{
    #[Test]
    public function markdownContainsHeaderDescriptionContextAndAttachments(): void
    {
        $markdown = $this->export(ReportFixture::report());

        self::assertStringStartsWith("# Cannot select an image\n", $markdown);
        self::assertStringContainsString('| Report ID | CR-7K3Q-9XMA-2B4F |', $markdown);
        self::assertStringContainsString('| Created | 2026-09-16 10:20 UTC |', $markdown);
        self::assertStringContainsString("## Description\n\n> The image selector shows no files.\n> It worked yesterday.\n", $markdown);
        self::assertStringContainsString("## Context\n\n" . 'Content Element "Hero teaser"', $markdown);
        self::assertStringContainsString("### Record\n", $markdown);
        self::assertStringContainsString('| Type | Text & Media (CType = textmedia) |', $markdown);
        self::assertStringContainsString('- CR-7K3Q-9XMA-2B4F-screenshot.png (image/png, 3×2 px, 99 B, SHA-256 ' . str_repeat('a', 64) . ')', $markdown);
    }

    #[Test]
    public function userInputCannotInjectHtmlOrBreakTables(): void
    {
        $markdown = $this->export(ReportFixture::report(description: "<img src=x onerror=alert(1)>\n| fake | row |"));

        self::assertStringNotContainsString('<img', $markdown);
        self::assertStringContainsString('> &lt;img src=x onerror=alert(1)&gt;', $markdown);
        self::assertStringContainsString('> \| fake \| row \|', $markdown);
    }

    #[Test]
    public function userTextCannotCreateImagesLinksOrStructure(): void
    {
        $document = ReportFixture::document();
        $document['summary'] = 'Page "![pixel](https://tracker.example/p.gif)" · *site* main';
        $document['context']['page']['title'] = '[Home](javascript:alert(1)) <b>x</b>';
        $report = new \Priebera\ContextReporter\Domain\Report(
            identifier: 'CR-7K3Q-9XMA-2B4F',
            createdAt: new \DateTimeImmutable('2026-09-16T10:20:30+00:00'),
            reporterUid: 3,
            source: \Priebera\ContextReporter\Domain\ReportSource::FormEngine,
            title: 'Broken ![tracking](https://example.test/pixel) and [click](https://evil.example "x") `code` *bold*',
            description: implode("\n", [
                '# Not a heading',
                '- not a list',
                '1. not numbered',
                '=====',
                '[ref]: https://evil.example/definition',
                'See <https://evil.example> and https://evil.example/path?x=1 or www.evil.example',
                'Ping @admin and write to erika@example.com ~~gone~~ _em_ & &copy;',
                '```',
                '| a | b |',
            ]),
            document: \Priebera\ContextReporter\Domain\ContextDocument::fromArray($document),
        );

        $markdown = $this->export($report);

        self::assertDoesNotMatchRegularExpression('/(?<!\\\\)\\]\\(/', $markdown, 'No unescaped link or image destination');
        self::assertDoesNotMatchRegularExpression('/(?<!\\\\)@admin/', $markdown, 'No unescaped mention');
        foreach (['![', '[click]', '[Home]', '[ref]', '<https://evil', '<b>', '<img', '*bold*', '~~gone~~', '&copy;', '| a |', "\n> # ", "\n> - ", "\n> 1. ", "\n> =====", "\n> ```"] as $active) {
            self::assertStringNotContainsString($active, $markdown, $active);
        }
        self::assertStringStartsWith('# Broken !\\[tracking\\](`https://example.test/pixel`) and \\[click\\](`https://evil.example` "x") \\`code\\` \\*bold\\*' . "\n", $markdown);
        self::assertStringContainsString("> \\# Not a heading\n> \\- not a list\n> 1\\. not numbered\n> \\=====\n", $markdown);
        self::assertStringContainsString('> See &lt;`https://evil.example`&gt; and `https://evil.example/path?x=1` or `www.evil.example`', $markdown);
        self::assertStringContainsString('> Ping \\@admin and write to erika\\@example.com \\~\\~gone\\~\\~ \\_em\\_ & &amp;copy;', $markdown);
        self::assertStringContainsString('Page "!\\[pixel\\](`https://tracker.example/p.gif`)" · \\*site\\* main', $markdown);
    }

    #[Test]
    public function exporterFormattingAndLinksAreKept(): void
    {
        $payload = (new ReportPayloadFactory(new ExtensionInfo('1.0.0')))->create(ReportFixture::report(), 'https://example.com/typo3/module/system/context-reports/show?report=CR-7K3Q-9XMA-2B4F');

        $markdown = (new MarkdownReportExporter(new ContextDetailsFormatter()))->export($payload);

        self::assertStringContainsString('| Report link | <https://example.com/typo3/module/system/context-reports/show?report=CR-7K3Q-9XMA-2B4F> |', $markdown);
        self::assertStringContainsString('| Backend link | <https://example.com/typo3/module/web/layout?id=1> |', $markdown);
        self::assertStringContainsString("\n## Description\n", $markdown);
        self::assertStringContainsString("\n---\n_Generated by TYPO3 Context Reporter 1.0.0._\n", $markdown);
    }

    #[Test]
    public function emptyDescriptionIsMarkedAsSuch(): void
    {
        $markdown = $this->export(ReportFixture::report(withScreenshot: false, description: ''));

        self::assertStringContainsString("## Description\n\n_No description given._\n", $markdown);
        self::assertStringContainsString("## Attachments\n\n_None._\n", $markdown);
    }

    private function export(\Priebera\ContextReporter\Domain\Report $report): string
    {
        $payload = (new ReportPayloadFactory(new ExtensionInfo('1.0.0')))->create($report);
        return (new MarkdownReportExporter(new ContextDetailsFormatter()))->export($payload);
    }
}
