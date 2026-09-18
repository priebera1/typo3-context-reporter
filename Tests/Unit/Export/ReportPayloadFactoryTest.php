<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\ExtensionInfo;
use Priebera\ContextReporter\Export\ReportPayloadFactory;
use Priebera\ContextReporter\Tests\Unit\Fixtures\ReportFixture;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ReportPayloadFactoryTest extends UnitTestCase
{
    #[Test]
    public function payloadFollowsTheDocumentedSchema(): void
    {
        $payload = $this->createFactory()->create(ReportFixture::report(), 'https://example.com/typo3/module/system/context-reports/show?report=CR-7K3Q-9XMA-2B4F');

        self::assertSame([
            'schema',
            'id',
            'createdAt',
            'source',
            'title',
            'description',
            'summary',
            'subject',
            'project',
            'reporter',
            'context',
            'system',
            'browser',
            'attachments',
            'links',
            'generator',
        ], array_keys($payload));
        self::assertSame('context-reporter.report.v1', $payload['schema']);
        self::assertSame('CR-7K3Q-9XMA-2B4F', $payload['id']);
        self::assertSame('2026-09-16T10:20:30+00:00', $payload['createdAt']);
        self::assertSame('formEngine', $payload['source']);
        self::assertSame('Cannot select an image', $payload['title']);
        self::assertSame(ReportFixture::document()['context'], $payload['context']);
        self::assertSame(['report' => 'https://example.com/typo3/module/system/context-reports/show?report=CR-7K3Q-9XMA-2B4F'], $payload['links']);
        self::assertSame(['name' => 'TYPO3 Context Reporter', 'package' => 'priebera/typo3-context-reporter', 'version' => '1.2.3'], $payload['generator']);
    }

    #[Test]
    public function attachmentMetadataIsListedWithoutBinaryContentByDefault(): void
    {
        $payload = $this->createFactory()->create(ReportFixture::report());

        self::assertSame([[
            'type' => 'screenshot',
            'filename' => 'CR-7K3Q-9XMA-2B4F-screenshot.png',
            'mediaType' => 'image/png',
            'size' => 99,
            'width' => 3,
            'height' => 2,
            'sha256' => str_repeat('a', 64),
        ]], $payload['attachments']);
        self::assertArrayNotHasKey('links', $payload);
    }

    #[Test]
    public function screenshotContentIsEmbeddedAsBase64WhenRequested(): void
    {
        $payload = $this->createFactory()->create(ReportFixture::report(), '', 'binary-content');

        self::assertSame(base64_encode('binary-content'), $payload['attachments'][0]['contentBase64']);
    }

    #[Test]
    public function reportWithoutScreenshotHasAnEmptyAttachmentList(): void
    {
        $payload = $this->createFactory()->create(ReportFixture::report(withScreenshot: false), '', 'ignored');

        self::assertSame([], $payload['attachments']);
    }

    #[Test]
    public function payloadIsJsonSerializableAndEmptyContextBecomesAnObject(): void
    {
        $report = ReportFixture::report();
        $document = ReportFixture::document();
        unset($document['context'], $document['reporter'], $document['browser']);
        $report = new \Priebera\ContextReporter\Domain\Report(
            $report->identifier,
            $report->createdAt,
            $report->reporterUid,
            $report->source,
            $report->title,
            $report->description,
            \Priebera\ContextReporter\Domain\ContextDocument::fromArray($document),
        );

        $json = json_encode($this->createFactory()->create($report), JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

        self::assertInstanceOf(\stdClass::class, $decoded->context);
        self::assertObjectNotHasProperty('reporter', $decoded);
        self::assertObjectNotHasProperty('browser', $decoded);
    }

    private function createFactory(): ReportPayloadFactory
    {
        return new ReportPayloadFactory(new ExtensionInfo('1.2.3'));
    }
}
