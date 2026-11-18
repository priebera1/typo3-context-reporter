<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Delivery\Email;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Delivery\Email\ReportEmailModel;
use Priebera\ContextReporter\Tests\Unit\Fixtures\ReportFixture;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ReportEmailModelTest extends UnitTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function urls(): iterable
    {
        yield 'https' => ['https://example.com/typo3/x?y=1', 'https://example.com/typo3/x?y=1'];
        yield 'http' => ['http://localhost:8080/typo3/', 'http://localhost:8080/typo3/'];
        yield 'javascript' => ['javascript:alert(1)', ''];
        yield 'data' => ['data:text/html,<b>x</b>', ''];
        yield 'protocol relative' => ['//evil.example.com/', ''];
        yield 'credentials' => ['https://user:pass@example.com/', ''];
        yield 'control characters' => ["https://example.com/\nBcc: x", ''];
        yield 'quote' => ['https://example.com/" onmouseover="x', ''];
    }

    #[Test]
    #[DataProvider('urls')]
    public function onlyHttpLinksBecomeLinks(string $url, string $expected): void
    {
        self::assertSame($expected, ReportEmailModel::url($url));
    }

    #[Test]
    public function hostileLinksInThePayloadAreNoLinks(): void
    {
        $payload = $this->payload();
        $payload['links']['report'] = 'javascript:alert(1)';
        $payload['subject']['backendUrl'] = 'data:text/html,x';
        $payload['context']['page']['frontendUrl'] = 'javascript:alert(2)';
        $model = $this->create($payload, hub: ['reference' => 'LW-1', 'url' => 'javascript:alert(3)']);

        self::assertSame('', $model['reportUrl']);
        self::assertNull($model['object']['action']);
        self::assertSame(['reference' => 'LW-1', 'url' => ''], $model['hub']);
        self::assertNotContains('Website address', array_column($model['object']['facts'], 'label'));
        self::assertSame('Context Reporter Hub: LW-1', $model['markers']['email.links']);
        self::assertNull($this->create($this->payload(), hub: ['reference' => 'not a reference', 'url' => ''])['hub']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string, string}>
     */
    public static function reporters(): iterable
    {
        yield 'name and username' => [['uid' => 3, 'username' => 'editor', 'realName' => 'Erika Editor'], 'Erika Editor', 'editor', 'Erika Editor (editor)'];
        yield 'real name not shared' => [['uid' => 3, 'username' => 'editor'], 'editor', 'real name not shared', 'editor (real name not shared)'];
        yield 'only the uid' => [['uid' => 3], 'Backend user #3', 'name not shared', 'backend user #3 (name not shared)'];
        yield 'nothing' => [[], 'Not shared', '', 'a backend user (not shared)'];
    }

    /**
     * @param array<string, mixed> $reporter
     */
    #[Test]
    #[DataProvider('reporters')]
    public function theReporterIsNamedAsThePrivacySettingsAllow(array $reporter, string $primary, string $secondary, string $text): void
    {
        $payload = $this->payload();
        $payload['reporter'] = $reporter;
        $model = $this->create($payload);
        self::assertSame($primary, $model['reporter']['primary']);
        self::assertSame($secondary, $model['reporter']['secondary']);
        self::assertSame($text, $model['markers']['reporter.display']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string|null}>
     */
    public static function objects(): iterable
    {
        yield 'content element' => [['type' => 'record', 'table' => 'tt_content', 'uid' => 12, 'label' => 'Hero', 'backendUrl' => 'https://example.com/e'], 'Open content element in TYPO3'];
        yield 'page' => [['type' => 'page', 'table' => 'pages', 'uid' => 2, 'label' => 'About', 'backendUrl' => 'https://example.com/p'], 'Open page in TYPO3'];
        yield 'news record' => [['type' => 'record', 'table' => 'tx_news_domain_model_news', 'uid' => 4, 'label' => 'Launch', 'backendUrl' => 'https://example.com/n'], 'Open record in TYPO3'];
        yield 'file' => [['type' => 'file', 'identifier' => '1:/user_upload/a.pdf', 'label' => 'a.pdf', 'backendUrl' => 'https://example.com/f'], 'Open containing folder in TYPO3'];
        yield 'folder' => [['type' => 'folder', 'identifier' => '1:/user_upload/', 'label' => 'user_upload', 'backendUrl' => 'https://example.com/d'], 'Open folder in TYPO3'];
        yield 'without link' => [['type' => 'backend', 'label' => ''], null];
    }

    /**
     * @param array<string, mixed> $subject
     */
    #[Test]
    #[DataProvider('objects')]
    public function theObjectLinkSaysWhatItOpensAndIsNeverTheReportLink(array $subject, ?string $label): void
    {
        $payload = $this->payload();
        $payload['subject'] = $subject;
        $model = $this->create($payload);
        self::assertSame($label, $model['object']['action']['label'] ?? null);
        self::assertStringStartsWith('Report in TYPO3: https://example.com/typo3/module/system/context-reports/show', $model['markers']['email.links']);
        self::assertNotSame('', $model['object']['label']);
    }

    #[Test]
    public function theWhereBlockNamesThePlaceAndFindingsAreCounted(): void
    {
        $findings = ['groups' => [['title' => 'Files', 'items' => ['Images: "a.jpg" – Reference hidden', 'Media: missing']], ['title' => 'Visibility', 'items' => ['Hidden']]], 'note' => 'Note.'];
        $model = $this->create($this->payload(), findings: $findings);

        self::assertSame(['Page', 'Site', 'Language', 'Workspace', 'Module', 'Website address'], array_column($model['object']['facts'], 'label'));
        self::assertSame('Home [1]', $model['object']['facts'][0]['value']);
        self::assertSame(['example.com/', 'https://example.com/'], [$model['object']['facts'][5]['value'], $model['object']['facts'][5]['url']]);
        self::assertSame('Content Element · Text & Media', $model['object']['meta']);
        self::assertSame('tt_content:12', $model['object']['identifier']);
        self::assertCount(3, $model['object']['factRows']);
        self::assertSame('Content Element “Hero teaser” on page Home · 3 findings · CR-7K3Q-9XMA-2B4F', $model['preheader']);
        self::assertSame($findings, $model['findings']);
        self::assertNull($this->create($this->payload(), findings: ['groups' => [], 'note' => 'Note.'])['findings']);
        self::assertStringContainsString('no findings', $this->create($this->payload())['preheader']);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{groups: list<array{title: string, items: list<string>}>, note: string}|null $findings
     * @param array{reference: string, url: string}|null $hub
     * @return array<string, mixed>
     */
    private function create(array $payload, ?array $findings = null, ?array $hub = null): array
    {
        return ReportEmailModel::create($payload, $findings, [], $hub, null, false, '', 'UTC');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $document = ReportFixture::document();
        return [
            'id' => 'CR-7K3Q-9XMA-2B4F',
            'createdAt' => '2026-09-16T10:20:30+00:00',
            'source' => 'contextMenu',
            'title' => 'Cannot select an image',
            'description' => '',
            'summary' => $document['summary'],
            'subject' => $document['subject'],
            'project' => $document['project'],
            'reporter' => $document['reporter'],
            'context' => $document['context'],
            'links' => ['report' => 'https://example.com/typo3/module/system/context-reports/show?report=CR-7K3Q-9XMA-2B4F'],
        ];
    }
}
