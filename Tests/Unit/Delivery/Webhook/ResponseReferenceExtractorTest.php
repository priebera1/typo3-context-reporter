<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Delivery\Webhook;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Delivery\Webhook\ResponseReferenceExtractor;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ResponseReferenceExtractorTest extends UnitTestCase
{
    #[Test]
    public function referenceAndUrlAreReadFromJsonResponses(): void
    {
        $result = (new ResponseReferenceExtractor())->extract(
            '{"reference":"SUP-42","url":"https://desk.example.com/tickets/42"}',
            'application/json; charset=utf-8',
        );

        self::assertSame(['reference' => 'SUP-42', 'url' => 'https://desk.example.com/tickets/42'], $result);
    }

    #[Test]
    public function commonAlternativeKeysAndNestedDataAreSupported(): void
    {
        $extractor = new ResponseReferenceExtractor();

        self::assertSame(
            ['reference' => 'PROJ-7', 'url' => 'https://jira.example.com/browse/PROJ-7'],
            $extractor->extract('{"data":{"key":"PROJ-7","html_url":"https://jira.example.com/browse/PROJ-7"}}', 'application/json'),
        );
        self::assertSame(
            ['reference' => '1234', 'url' => ''],
            $extractor->extract('{"id":1234}', 'application/json'),
        );
    }

    #[Test]
    public function nonJsonAndUnsafeValuesAreIgnored(): void
    {
        $extractor = new ResponseReferenceExtractor();

        self::assertSame(['reference' => '', 'url' => ''], $extractor->extract('<html>ok</html>', 'text/html'));
        self::assertSame(['reference' => '', 'url' => ''], $extractor->extract('{broken', 'application/json'));
        self::assertSame(
            ['reference' => 'ok', 'url' => ''],
            $extractor->extract('{"reference":"ok","url":"javascript:alert(1)"}', 'application/json'),
        );
        self::assertSame(
            ['reference' => '', 'url' => ''],
            $extractor->extract('{"reference":{"nested":"object"}}', 'application/json'),
        );
    }

    #[Test]
    public function jsonBodiesAreDetectedEvenWithoutJsonContentType(): void
    {
        self::assertSame(
            ['reference' => 'n8n-99', 'url' => ''],
            (new ResponseReferenceExtractor())->extract('  {"reference":"n8n-99"}', 'text/plain'),
        );
    }

    #[Test]
    public function referenceValuesAreSanitizedAndTruncated(): void
    {
        $result = (new ResponseReferenceExtractor())->extract(
            (string)json_encode(['reference' => "A\nB" . str_repeat('c', 400)]),
            'application/json',
        );

        self::assertSame(255, mb_strlen($result['reference']));
        self::assertStringStartsWith('A B', $result['reference']);
    }
}
