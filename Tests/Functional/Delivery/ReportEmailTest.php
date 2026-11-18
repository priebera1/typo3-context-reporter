<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Delivery;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Domain\DeliveryAttempt;
use Priebera\ContextReporter\Domain\DeliveryStatus;
use Priebera\ContextReporter\Report\ReportService;
use Priebera\ContextReporter\Report\ReportSubmission;
use Priebera\ContextReporter\Report\SubmissionResult;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The report email as it leaves TYPO3: a multipart message with the customisable plain text and the HTML part
 * rendered by Fluid, both naming the Hub reference only after the Hub acknowledged the report, escaping report
 * text, and staying small enough for Gmail. The Hub is delivered before the email; each destination stays
 * independent of the others' failures.
 */
final class ReportEmailTest extends AbstractContextReporterTestCase
{
    private const SCREENSHOT = __DIR__ . '/../../Unit/Fixtures/Images/screenshot.png';
    private const HUB_URL = 'https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P';
    private const HUB_SECRET = 'whsec_8Fq2LmZx4RtY7vB1nKc3Wd9PaQ5sT0uV';
    private const GMAIL_CLIPPING_BYTES = 102400;

    /** custom_email_template: a site package with a trimmed email template */
    protected array $testExtensionsToLoad = [
        'priebera/typo3-context-reporter',
        __DIR__ . '/../Fixtures/Extensions/custom_email_template',
    ];

    private string $mailbox = '';

    /**
     * @var array<string, Response> Answers per host
     */
    private array $answers = [];

    /**
     * @var list<string>
     */
    private array $requestedHosts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailbox = $this->instancePath . '/typo3temp/var/transient/report-email.mbox';
        @unlink($this->mailbox);
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = 'mbox';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file'] = $this->mailbox;
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] = 'typo3@example.com';
        $answers = &$this->answers;
        $hosts = &$this->requestedHosts;
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['contextReporterTest'] = static function (callable $next) use (&$answers, &$hosts): \Closure {
            return static function (RequestInterface $request, array $options) use (&$answers, &$hosts) {
                $hosts[] = $request->getUri()->getHost();
                return Create::promiseFor($answers[$request->getUri()->getHost()] ?? new Response(500));
            };
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['contextReporterTest']);
        parent::tearDown();
    }

    #[Test]
    public function theEmailIsMultipartAndNamesTheHubReferenceAfterTheHubAcknowledgedTheReport(): void
    {
        $this->configure(hub: true);
        $this->answers['hub.example.com'] = new Response(200, ['Content-Type' => 'application/json'], '{"reference":"LW-152","url":"https://hub.example.com/app/reports/LW-152"}');

        $result = $this->send('Image in "Our story" is not displayed', "The team photo was added yesterday.\nThe page only shows the text.");

        self::assertSame(['hub' => DeliveryStatus::Succeeded, 'email' => DeliveryStatus::Succeeded], $this->statuses($result), 'The Hub is delivered first');
        self::assertSame(['hub.example.com'], $this->requestedHosts);
        [$headers, $text, $html] = $this->readMail();
        self::assertMatchesRegularExpression('~Content-Type: multipart/mixed~', $headers);
        self::assertStringContainsString('Content-Type: multipart/alternative', (string)file_get_contents($this->mailbox));
        self::assertStringContainsString('X-Context-Reporter-Report: ' . $result->report->identifier, $headers);
        self::assertStringContainsString('Subject: [Functional Test Portal] Image in "Our story" is not displayed (' . $result->report->identifier . ')', $headers);

        // Plain text: accurate links, real attachments, the Hub line, the reply note
        self::assertStringContainsString("OPEN\nReport in TYPO3: http", $text);
        self::assertStringContainsString("\nContent element in TYPO3: http", $text);
        self::assertStringContainsString("\nContext Reporter Hub: LW-152 https://hub.example.com/app/reports/LW-152\n", $text);
        self::assertStringContainsString("ATTACHMENTS\n" . $result->report->identifier . '-screenshot.png (screenshot, PNG, 3 × 2 px, 99 bytes)' . "\n" . $result->report->identifier . '.json (the complete, machine-readable report)', $text);
        self::assertStringContainsString('Reply to this email to answer Erika Editor.', $text);
        self::assertStringContainsString('Reported by Erika Editor (editor) on ', $text);

        // HTML: Concept "Title block" with the Hub cell and both actions
        self::assertStringStartsWith('<!DOCTYPE html>', $html);
        self::assertStringContainsString('>Context Reporter Hub</div>', $html);
        self::assertStringContainsString('href="https://hub.example.com/app/reports/LW-152"', $html);
        self::assertStringContainsString('>Open in Context Reporter Hub</a>', $html);
        self::assertMatchesRegularExpression('~<a class="b1" href="[^"]*context-reports/show\?report=' . $result->report->identifier . '"[^>]*>Open report in TYPO3</a>~', $html);
        self::assertMatchesRegularExpression('~href="[^"]*record/edit[^"]*"[^>]*>Open content element in TYPO3</a>~', $html);
        self::assertStringContainsString('>Received by the Hub</div>', $html);
        self::assertStringContainsString('Image in &quot;Our story&quot; is not displayed', $html);
        self::assertStringContainsString("The team photo was added yesterday.\nThe page only shows the text.", $html);
        self::assertStringContainsString('>Screenshot, 3 × 2 px, 99 bytes</div>', $html);
        self::assertStringContainsString('>The complete, machine-readable report</div>', $html);
        self::assertStringContainsString('Also in the attached JSON file.', $html);
        self::assertStringContainsString('Reply to this email to answer Erika Editor.', $html);
        self::assertLessThan(self::GMAIL_CLIPPING_BYTES, strlen($html));
        foreach (['<img', '<link', '<script', 'src=', '@import', 'url('] as $remote) {
            self::assertStringNotContainsString($remote, $html, 'No images, scripts or remote resources');
        }
    }

    #[Test]
    public function withoutAHubAcknowledgementTheEmailIsSentWithoutHubAndEveryDestinationStaysIndependent(): void
    {
        $this->configure(hub: true, webhook: true);
        $this->answers['hub.example.com'] = new Response(503, ['Content-Type' => 'application/json'], '{"error":"unavailable"}');
        $this->answers['hooks.example.com'] = new Response(500, [], 'Temporary outage');

        $result = $this->send('Teaser is broken', '');

        self::assertSame(['hub' => DeliveryStatus::Failed, 'email' => DeliveryStatus::Succeeded, 'webhook' => DeliveryStatus::Failed], $this->statuses($result));
        [, $text, $html] = $this->readMail();
        self::assertStringNotContainsString('Context Reporter Hub', $text);
        self::assertStringNotContainsString('Context Reporter Hub', $html);
        self::assertStringContainsString('>Reported via</div>', $html);
        self::assertStringContainsString('No description was given.', $html);
        self::assertStringContainsString("WHAT HAPPENED\nNo description was given.\n", $text);
    }

    #[Test]
    public function reportTextIsEscapedAndOnlyGeneratedLinksAreLinks(): void
    {
        $this->configure(attachJson: '0');
        $result = $this->send('<script>alert(1)</script> & <b>bold</b> {report.id}', "<img src=x onerror=alert(1)>\n<a href=\"javascript:alert(2)\">click</a> https://evil.example.com/x");

        [, $text, $html] = $this->readMail();
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<b>bold', $html);
        self::assertStringNotContainsString('href="javascript', $html);
        self::assertStringNotContainsString('href="https://evil.example.com', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &lt;b&gt;bold&lt;/b&gt; {report.id}', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        // Plain text keeps the text literally, markers in user text are not expanded
        self::assertStringContainsString('<script>alert(1)</script> & <b>bold</b> {report.id}', $text);
        self::assertStringNotContainsString('.json', $text);
        self::assertStringNotContainsString('JSON file', $html);
        self::assertSame([], array_filter($result->attempts, static fn(DeliveryAttempt $attempt): bool => $attempt->status !== DeliveryStatus::Succeeded));
    }

    #[Test]
    public function longValuesStayWithinTheSizeBudgetAndTheHtmlPartCanBeSwitchedOff(): void
    {
        $this->configure();
        $this->send(str_repeat('Overlong title without spaces_', 5), str_repeat('A long description line with a very_long_identifier_without_breaks_' . str_repeat('x', 120) . "\n", 20));
        [, , $html] = $this->readMail();
        self::assertLessThan(self::GMAIL_CLIPPING_BYTES, strlen($html));
        // Long values may break anywhere (.v: word-break and overflow-wrap)
        self::assertMatchesRegularExpression('~<h1 class="h1 t1 v"~', $html);
        self::assertMatchesRegularExpression('~<td class="px t1 v"[^>]*white-space:pre-wrap;">A long description~', $html);

        @unlink($this->mailbox);
        $this->configure(htmlVersion: '0');
        $this->send('Plain text only', '');
        $mail = (string)file_get_contents($this->mailbox);
        self::assertStringNotContainsString('text/html', $mail);
        self::assertStringContainsString('Plain text only', $mail);
    }

    /**
     * The formatted version shows the content of the shipped template. An
     * administrator who trims the email with a custom template (here: title, ID
     * and link) gets exactly that, as plain text: the HTML version never adds
     * the description, the reporter or the technical context the template
     * leaves out. With the shipped template, both parts carry the same report.
     */
    #[Test]
    public function aTrimmedCustomTemplateIsSentAsPlainTextOnlyAndBothPartsOfTheShippedTemplateMatch(): void
    {
        $this->configure(attachJson: '0');
        $this->send('Teaser is broken', 'The login data is on a sticky note.');
        [, $text, $html] = $this->readMail();
        foreach (['text' => $text, 'html' => $html] as $part => $body) {
            self::assertStringContainsString('Teaser is broken', $body, $part);
            self::assertStringContainsString('The login data is on a sticky note.', $body, $part);
            self::assertStringContainsString('Erika Editor', $body, $part);
        }

        @unlink($this->mailbox);
        $this->configure(attachJson: '0', bodyTemplate: 'EXT:custom_email_template/Resources/Private/Templates/Email/Short.txt');
        $result = $this->send('Teaser is broken', 'The login data is on a sticky note.');
        $mail = (string)file_get_contents($this->mailbox);
        [, $text, $html] = $this->readMail();

        self::assertSame(DeliveryStatus::Succeeded, $result->attempts[0]->status);
        self::assertStringNotContainsString('text/html', $mail, 'No formatted version');
        self::assertSame('', $html);
        self::assertMatchesRegularExpression('~^New problem report: Teaser is broken\n' . $result->report->identifier . '\nOpen it in TYPO3: http\S+context-reports/show\?report=' . $result->report->identifier . '$~', $text);
        foreach (['sticky note', 'WHERE IT HAPPENED', 'TECHNICAL CONTEXT', 'Typo3 version', 'tt_content'] as $omitted) {
            self::assertStringNotContainsString($omitted, $mail, 'Left out by the template: ' . $omitted);
        }
        // The reporter is only named in the Reply-To header (replies go to the reporter), not in the body
        self::assertStringNotContainsString('Erika', $text);
    }

    private function configure(bool $hub = false, bool $webhook = false, string $attachJson = '1', string $htmlVersion = '1', string $bodyTemplate = ''): void
    {
        $configuration = [
            'email' => ['enabled' => '1', 'recipients' => 'support@example.com', 'replyToReporter' => '1', 'attachJson' => $attachJson, 'htmlVersion' => $htmlVersion, 'bodyTemplate' => $bodyTemplate],
            'privacy' => ['reporterRealName' => '1', 'reporterEmail' => '1'],
        ];
        if ($hub) {
            $configuration['hub'] = ['enabled' => '1', 'url' => self::HUB_URL, 'secret' => self::HUB_SECRET];
        }
        if ($webhook) {
            $configuration['webhook'] = ['enabled' => '1', 'url' => 'https://hooks.example.com/report'];
        }
        $this->configureExtension($configuration);
    }

    private function send(string $title, string $description): SubmissionResult
    {
        $editor = $this->loginBackendUser(self::EDITOR);
        $request = CollectionRequest::fromArray(['source' => 'contextMenu', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 10]], new BackendLocationFactory());
        $service = $this->get(ReportService::class);
        $prepared = $service->prepare($request, $editor, $GLOBALS['TYPO3_REQUEST']);
        return $service->submit(new ReportSubmission($prepared->draftToken, $title, $description, (string)file_get_contents(self::SCREENSHOT), true), $editor);
    }

    /**
     * @return array<string, DeliveryStatus>
     */
    private function statuses(SubmissionResult $result): array
    {
        $statuses = [];
        foreach ($result->attempts as $attempt) {
            $statuses[$attempt->destination] = $attempt->status;
        }
        return $statuses;
    }

    /**
     * The headers, the plain text and the HTML part of the last email.
     *
     * @return array{string, string, string}
     */
    private function readMail(): array
    {
        $mail = str_replace("\r\n", "\n", (string)file_get_contents($this->mailbox));
        // Unfold long header lines (RFC 5322)
        $headers = (string)preg_replace('/\n(?=[ \t])/', '', substr($mail, 0, (int)strpos($mail, "\n\n")));
        $parts = ['plain' => '', 'html' => ''];
        // Split at the declared MIME boundaries: the "--" signature of the text and quoted-printable lines of the
        // HTML (e.g. "-->" of a conditional comment) may start with "--" as well
        preg_match_all('~boundary=("?)([^"\s;]+)\1~', $mail, $boundaries);
        $chunks = [$mail];
        foreach ($boundaries[2] as $boundary) {
            $chunks = array_merge(...array_map(static fn(string $chunk): array => explode("\n--" . $boundary, $chunk), $chunks));
        }
        $matches = [];
        foreach ($chunks as $chunk) {
            if (preg_match('~^(?:--)?\n?Content-Type: text/(plain|html); charset=utf-8\nContent-Transfer-Encoding: ([a-z0-9-]+)\n\n(.*)$~s', ltrim($chunk, "\n"), $match) === 1) {
                $matches[] = $match;
            }
        }
        foreach ($matches as [, $type, $encoding, $body]) {
            $parts[$type] = match ($encoding) {
                'quoted-printable' => quoted_printable_decode($body),
                'base64' => (string)base64_decode($body, true),
                default => $body,
            };
        }
        return [$headers, rtrim(str_replace("\r\n", "\n", $parts['plain']), "\n"), rtrim(str_replace("\r\n", "\n", $parts['html']), "\n")];
    }
}
