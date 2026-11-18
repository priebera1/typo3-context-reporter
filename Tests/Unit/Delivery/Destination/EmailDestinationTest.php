<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Delivery\Destination;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\ExtensionInfo;
use Priebera\ContextReporter\Configuration\ExtensionSettingsFactory;
use Priebera\ContextReporter\Delivery\DeliveryRequest;
use Priebera\ContextReporter\Delivery\Destination\EmailDestination;
use Priebera\ContextReporter\Delivery\Email\EmailTemplateLoader;
use Priebera\ContextReporter\Delivery\Email\MailConfigurationInspector;
use Priebera\ContextReporter\Delivery\Email\ReportEmailHtmlRenderer;
use Priebera\ContextReporter\Domain\DeliveryAttempt;
use Priebera\ContextReporter\Domain\DeliveryStatus;
use Priebera\ContextReporter\Export\ContextDetailsFormatter;
use Priebera\ContextReporter\Export\JsonReportExporter;
use Priebera\ContextReporter\Export\ReportMarkers;
use Priebera\ContextReporter\Export\ReportPayloadFactory;
use Priebera\ContextReporter\Template\MarkerRenderer;
use Priebera\ContextReporter\Tests\Unit\Fixtures\RecordingMailer;
use Priebera\ContextReporter\Tests\Unit\Fixtures\RecordingViewFactory;
use Priebera\ContextReporter\Tests\Unit\Fixtures\ReportFixture;
use Priebera\ContextReporter\Tests\Unit\Fixtures\SettingsProviderStub;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class EmailDestinationTest extends UnitTestCase
{
    private const DEFAULT_TEMPLATE = __DIR__ . '/../../../../Resources/Private/Templates/Email/Report.txt';

    private RecordingViewFactory $views;
    private string $timeZone = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->views = new RecordingViewFactory();
        $this->timeZone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['MAIL']);
        date_default_timezone_set($this->timeZone);
        parent::tearDown();
    }

    #[Test]
    public function reportIsSentAsPlainTextWithRenderedMarkersAndAttachments(): void
    {
        $mailer = $this->createMailer();
        $destination = $this->createDestination($mailer, [
            'recipients' => 'support@example.com, dev@example.com',
            'senderAddress' => 'typo3@example.com',
            'senderName' => 'Portal',
            'subject' => "[{project.name}] {report.title}\n({report.id})",
            'replyToReporter' => '1',
        ], "Hello\n{context.summary}\n{reporter.email}\n{unknown.marker}");

        $outcome = $destination->deliver(new DeliveryRequest(ReportFixture::report(), 'https://example.com/typo3/r', 1, 'id', static fn(): string => 'png-bytes'));

        self::assertTrue($outcome->successful, $outcome->message);
        self::assertSame('Email handed over to the mail transport for 2 recipients.', $outcome->message);
        $message = $mailer->messages[0];
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('[Agency Portal] Cannot select an image (CR-7K3Q-9XMA-2B4F)', $message->getSubject());
        self::assertSame(['support@example.com', 'dev@example.com'], array_map(static fn($address): string => $address->getAddress(), $message->getTo()));
        self::assertSame('typo3@example.com', $message->getFrom()[0]->getAddress());
        self::assertSame('erika@example.com', $message->getReplyTo()[0]->getAddress());
        self::assertSame("Hello\n" . ReportFixture::document()['summary'] . "\nerika@example.com\n{unknown.marker}", $message->getTextBody());
        self::assertSame('CR-7K3Q-9XMA-2B4F', $message->getHeaders()->get('X-Context-Reporter-Report')?->getBodyAsString());

        $attachments = $message->getAttachments();
        self::assertCount(2, $attachments);
        self::assertSame('CR-7K3Q-9XMA-2B4F-screenshot.png', $attachments[0]->getFilename());
        self::assertSame('png-bytes', $attachments[0]->getBody());
        self::assertSame('CR-7K3Q-9XMA-2B4F.json', $attachments[1]->getFilename());
        $json = json_decode($attachments[1]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('CR-7K3Q-9XMA-2B4F', $json['id']);
        self::assertArrayNotHasKey('contentBase64', $json['attachments'][0]);
    }

    #[Test]
    public function attachmentsAndReplyToCanBeDisabled(): void
    {
        $mailer = $this->createMailer();
        $destination = $this->createDestination($mailer, [
            'recipients' => 'support@example.com',
            'attachScreenshot' => '0',
            'attachJson' => '0',
        ]);

        $outcome = $destination->deliver(new DeliveryRequest(ReportFixture::report(), '', 1, 'id', static fn(): string => 'png-bytes'));

        self::assertTrue($outcome->successful);
        self::assertSame('Email handed over to the mail transport for 1 recipient.', $outcome->message);
        self::assertSame([], $mailer->messages[0]->getAttachments());
        self::assertSame([], $mailer->messages[0]->getReplyTo());
        self::assertSame([], $mailer->messages[0]->getFrom());
    }

    #[Test]
    public function transportErrorsBecomeFailures(): void
    {
        $mailer = $this->createMailer(new TransportException('Connection could not be established with host "smtp.example.com:465"'));

        $outcome = $this->createDestination($mailer, ['recipients' => 'support@example.com'])
            ->deliver(new DeliveryRequest(ReportFixture::report(), '', 1, 'id', static fn(): ?string => null));

        self::assertFalse($outcome->successful);
        self::assertSame('The server could not be reached (connection refused or blocked).', $outcome->message);
        self::assertStringNotContainsString('smtp.example.com', $outcome->message);
    }

    #[Test]
    public function transportErrorsDoNotRevealMailCredentials(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = [
            'transport' => 'smtp',
            'transport_smtp_server' => 'smtp.example.com:587',
            'transport_smtp_username' => 'agency-mailer@example.com',
            'transport_smtp_password' => 'app-specific-password',
        ];
        $mailer = $this->createMailer(new TransportException(
            'Failed to authenticate on SMTP server with username "agency-mailer@example.com" using the following authenticators: "LOGIN". '
            . 'Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535 Credentials app-specific-password rejected".',
        ));

        $outcome = $this->createDestination($mailer, ['recipients' => 'support@example.com'])
            ->deliver(new DeliveryRequest(ReportFixture::report(), '', 1, 'id', static fn(): ?string => null));

        self::assertFalse($outcome->successful);
        self::assertSame('The mail server rejected the login (authentication failed).', $outcome->message);
        self::assertStringNotContainsString('agency-mailer', $outcome->message);
        self::assertStringNotContainsString('app-specific-password', $outcome->message);
    }

    #[Test]
    public function anHtmlPartIsSentNextToThePlainTextUnlessSwitchedOff(): void
    {
        $mailer = $this->createMailer();
        $this->createDestination($mailer, ['recipients' => 'support@example.com'], 'Text {report.id}')
            ->deliver($this->request());
        self::assertSame('Text CR-7K3Q-9XMA-2B4F', $mailer->messages[0]->getTextBody());
        self::assertSame('<p>HTML part</p>', $mailer->messages[0]->getHtmlBody());
        self::assertSame('[Agency Portal] Cannot select an image (CR-7K3Q-9XMA-2B4F)', $this->views->rendered[0]['model']['subjectLine']);

        $textOnly = $this->createMailer();
        $this->createDestination($textOnly, ['recipients' => 'support@example.com', 'htmlVersion' => '0'], 'Text')
            ->deliver($this->request());
        self::assertSame('Text', $textOnly->messages[0]->getTextBody());
        self::assertNull($textOnly->messages[0]->getHtmlBody());
    }

    /**
     * A custom template decides what the email contains; the formatted version
     * (the content of the shipped template) is not even rendered.
     */
    #[Test]
    public function aCustomTemplateIsSentAsPlainTextOnly(): void
    {
        $mailer = $this->createMailer();
        $outcome = $this->createDestination($mailer, [
            'recipients' => 'support@example.com',
            'bodyTemplate' => 'EXT:my_sitepackage/Resources/Private/Templates/Email/Short.txt',
        ], '{report.title} {report.id}')->deliver($this->request());

        self::assertTrue($outcome->successful);
        self::assertSame('Email handed over to the mail transport for 1 recipient.', $outcome->message);
        self::assertSame('Cannot select an image CR-7K3Q-9XMA-2B4F', $mailer->messages[0]->getTextBody());
        self::assertNull($mailer->messages[0]->getHtmlBody());
        self::assertSame([], $this->views->rendered);

        // The shipped template, also when it is entered explicitly: both parts
        $shipped = $this->createMailer();
        $this->createDestination($shipped, [
            'recipients' => 'support@example.com',
            'bodyTemplate' => ExtensionSettingsFactory::DEFAULT_BODY_TEMPLATE,
        ], 'Text')->deliver($this->request());
        self::assertSame('<p>HTML part</p>', $shipped->messages[0]->getHtmlBody());
    }

    #[Test]
    public function anHtmlProblemNeverCostsTheEmail(): void
    {
        $this->views = new RecordingViewFactory(static fn(): string => throw new \RuntimeException('Template broken'));
        $mailer = $this->createMailer();
        $outcome = $this->createDestination($mailer, ['recipients' => 'support@example.com'], 'Text')->deliver($this->request());

        self::assertTrue($outcome->successful);
        self::assertSame('Email handed over to the mail transport for 1 recipient. The HTML version could not be rendered; the email was sent as plain text.', $outcome->message);
        self::assertSame('Text', $mailer->messages[0]->getTextBody());
        self::assertNull($mailer->messages[0]->getHtmlBody());
    }

    /**
     * @return iterable<string, array{array<string, string>, string, list<string>}>
     */
    public static function attachmentCombinations(): iterable
    {
        yield 'screenshot and JSON' => [[], "ATTACHMENTS\nCR-7K3Q-9XMA-2B4F-screenshot.png (screenshot, PNG, 3 × 2 px, 99 bytes)\nCR-7K3Q-9XMA-2B4F.json (the complete, machine-readable report)\n", ['PNG', 'JSON']];
        yield 'screenshot only' => [['attachJson' => '0'], "ATTACHMENTS\nCR-7K3Q-9XMA-2B4F-screenshot.png (screenshot, PNG, 3 × 2 px, 99 bytes)\n", ['PNG']];
        yield 'JSON only' => [['attachScreenshot' => '0'], "ATTACHMENTS\nCR-7K3Q-9XMA-2B4F.json (the complete, machine-readable report)\n", ['JSON']];
        yield 'nothing' => [['attachScreenshot' => '0', 'attachJson' => '0'], "ATTACHMENTS\nNone\n", []];
    }

    /**
     * @param array<string, string> $options
     * @param list<string> $tags
     */
    #[Test]
    #[DataProvider('attachmentCombinations')]
    public function bothPartsOnlyMentionWhatIsAttached(array $options, string $text, array $tags): void
    {
        $mailer = $this->createMailer();
        $this->createDestination($mailer, ['recipients' => 'support@example.com'] + $options, (string)file_get_contents(self::DEFAULT_TEMPLATE))
            ->deliver($this->request());

        $body = (string)$mailer->messages[0]->getTextBody();
        self::assertStringContainsString($text, $body);
        self::assertSame(count($tags), count($mailer->messages[0]->getAttachments()));
        self::assertSame($tags, array_column($this->views->rendered[0]['model']['attachments'], 'tag'));
        self::assertSame(($options['attachJson'] ?? '1') === '1', $this->views->rendered[0]['model']['jsonAttached']);
        if (($options['attachJson'] ?? '1') === '0') {
            self::assertStringNotContainsString('.json', $body);
        }
    }

    #[Test]
    public function aScreenshotThatCannotBeLoadedIsNotMentioned(): void
    {
        $mailer = $this->createMailer();
        $this->createDestination($mailer, ['recipients' => 'support@example.com', 'attachJson' => '0'], '{email.attachments}')
            ->deliver(new DeliveryRequest(ReportFixture::report(), '', 1, 'id', static fn(): ?string => null));

        self::assertSame('None', $mailer->messages[0]->getTextBody());
        self::assertSame([], $mailer->messages[0]->getAttachments());
    }

    #[Test]
    public function linksAreLabelledWithWhatTheyOpen(): void
    {
        $mailer = $this->createMailer();
        $this->createDestination($mailer, ['recipients' => 'support@example.com'], '{email.links}')->deliver($this->request());

        self::assertSame(
            "Report in TYPO3: https://example.com/typo3/module/system/context-reports/show?report=CR-7K3Q-9XMA-2B4F\n"
            . 'Content element in TYPO3: https://example.com/typo3/record/edit?edit%5Btt_content%5D%5B12%5D=edit',
            $mailer->messages[0]->getTextBody(),
        );
        $model = $this->views->rendered[0]['model'];
        self::assertSame('https://example.com/typo3/module/system/context-reports/show?report=CR-7K3Q-9XMA-2B4F', $model['reportUrl']);
        self::assertSame(['noun' => 'content element', 'label' => 'Open content element in TYPO3', 'url' => 'https://example.com/typo3/record/edit?edit%5Btt_content%5D%5B12%5D=edit'], $model['object']['action']);
    }

    /**
     * @return iterable<string, array{DeliveryStatus, string, string, array<string, mixed>, string, string}>
     */
    public static function hubOutcomes(): iterable
    {
        $hub = ['hub' => ['enabled' => '1', 'url' => 'https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P', 'secret' => 'whsec_8Fq2LmZx4RtY7vB1nKc3Wd9PaQ5sT0uV']];
        yield 'acknowledged with a trusted link' => [DeliveryStatus::Succeeded, 'LW-152', 'https://hub.example.com/app/reports/LW-152', $hub, 'Context Reporter Hub: LW-152 https://hub.example.com/app/reports/LW-152', 'https://hub.example.com/app/reports/LW-152'];
        yield 'acknowledged, link not on the Hub address' => [DeliveryStatus::Succeeded, 'LW-152', 'https://evil.example.com/app/reports/LW-152', $hub, 'Context Reporter Hub: LW-152', ''];
        yield 'Hub no longer configured' => [DeliveryStatus::Succeeded, 'LW-152', 'https://hub.example.com/app/reports/LW-152', [], 'Context Reporter Hub: LW-152', ''];
        yield 'failed' => [DeliveryStatus::Failed, '', '', $hub, '', ''];
    }

    /**
     * @param array<string, mixed> $configuration
     */
    #[Test]
    #[DataProvider('hubOutcomes')]
    public function theHubReferenceIsOnlyNamedAfterTheHubAcknowledgedTheReport(DeliveryStatus $status, string $reference, string $url, array $configuration, string $line, string $link): void
    {
        $mailer = $this->createMailer();
        $prior = ['hub' => new DeliveryAttempt(5, 'hub', 1, $status, new \DateTimeImmutable(), 'https://hub.example.com', 1, $status === DeliveryStatus::Succeeded ? 200 : 503, '', $reference, $url)];
        $this->createDestination($mailer, ['recipients' => 'support@example.com'], '{email.links}|{hub.reference}', $configuration)
            ->deliver(new DeliveryRequest(ReportFixture::report(), 'https://example.com/typo3/r', 1, 'id', static fn(): string => 'png-bytes', $prior));

        $body = (string)$mailer->messages[0]->getTextBody();
        $model = $this->views->rendered[0]['model'];
        if ($line === '') {
            self::assertStringNotContainsString('Hub', $body);
            self::assertNull($model['hub']);
            self::assertStringEndsWith('|', $body);
        } else {
            self::assertStringContainsString($line . '|' . $reference, $body);
            self::assertSame(['reference' => $reference, 'url' => $link], $model['hub']);
        }
    }

    #[Test]
    public function timesNameTheServerTimeZoneAndUtc(): void
    {
        date_default_timezone_set('Europe/Bratislava');
        $mailer = $this->createMailer();
        $this->createDestination($mailer, ['recipients' => 'support@example.com'], '{report.created}|{report.createdLocal}|{report.createdAt}')->deliver($this->request());
        self::assertSame('2026-09-16 12:20 CEST (10:20 UTC)|2026-09-16 12:20 CEST|2026-09-16 10:20 UTC', $mailer->messages[0]->getTextBody());
        self::assertSame(['local' => '16 Sep 2026, 12:20 CEST', 'utc' => '2026-09-16 10:20 UTC'], array_intersect_key($this->views->rendered[0]['model']['created'], ['local' => 0, 'utc' => 0]));

        date_default_timezone_set('UTC');
        $utc = $this->createMailer();
        $this->createDestination($utc, ['recipients' => 'support@example.com'], '{report.created}')->deliver($this->request());
        self::assertSame('2026-09-16 10:20 UTC', $utc->messages[0]->getTextBody());
    }

    #[Test]
    public function aHiddenRealNameIsSaidAndRepliesAreExplainedOnlyWithReplyTo(): void
    {
        $document = ReportFixture::document();
        unset($document['reporter']['realName'], $document['reporter']['email']);
        $report = ReportFixture::report(document: $document);
        $mailer = $this->createMailer();
        $this->createDestination($mailer, ['recipients' => 'support@example.com', 'replyToReporter' => '1'], '{reporter.display}|{email.footer}')
            ->deliver(new DeliveryRequest($report, '', 1, 'id', static fn(): string => 'png-bytes'));
        self::assertSame('editor (real name not shared)|Sent by Context Reporter for TYPO3 from Agency Portal (Production).', $mailer->messages[0]->getTextBody());
        self::assertSame(['primary' => 'editor', 'secondary' => 'real name not shared'], array_intersect_key($this->views->rendered[0]['model']['reporter'], ['primary' => 0, 'secondary' => 0]));

        $withReply = $this->createMailer();
        $this->createDestination($withReply, ['recipients' => 'support@example.com', 'replyToReporter' => '1'], '{reporter.display}|{email.footer}')->deliver($this->request());
        self::assertSame("Erika Editor (editor)|Sent by Context Reporter for TYPO3 from Agency Portal (Production).\nReply to this email to answer Erika Editor.", $withReply->messages[0]->getTextBody());
    }

    #[Test]
    public function targetDescriptionCountsRecipients(): void
    {
        $destination = $this->createDestination($this->createMailer(), ['recipients' => 'a@example.com b@example.com']);

        self::assertSame('2 recipients', $destination->describeTarget());
        self::assertTrue($destination->isEnabled());
    }

    #[Test]
    public function errorsBeforeTheTransportKeepTheirSanitizedText(): void
    {
        $mailer = $this->createMailer(new \RuntimeException('Invalid address "nobody@" in template'));

        $outcome = $this->createDestination($mailer, ['recipients' => 'support@example.com'])
            ->deliver(new DeliveryRequest(ReportFixture::report(), '', 1, 'id', static fn(): ?string => null));

        self::assertSame('RuntimeException: Invalid address "nobody@" in template', $outcome->message);
    }

    /**
     * @param array<string, string> $emailConfiguration
     * @param array<string, mixed> $configuration Other sections (e.g. the Hub)
     */
    private function createDestination(MailerInterface $mailer, array $emailConfiguration, string $bodyTemplate = '{context.details}', array $configuration = []): EmailDestination
    {
        $templateLoader = new class ($bodyTemplate) extends EmailTemplateLoader {
            public function __construct(private readonly string $template) {}

            public function load(string $path): string
            {
                return $this->template;
            }
        };
        return new EmailDestination(
            new SettingsProviderStub(['email' => array_merge(['enabled' => '1'], $emailConfiguration)] + $configuration),
            $mailer,
            new ReportPayloadFactory(new ExtensionInfo('1.0.0')),
            new JsonReportExporter(),
            new ReportMarkers(new ContextDetailsFormatter()),
            new MarkerRenderer(),
            $templateLoader,
            new MailConfigurationInspector(),
            new ContextDetailsFormatter(),
            new ReportEmailHtmlRenderer($this->views),
        );
    }

    private function request(): DeliveryRequest
    {
        return new DeliveryRequest(ReportFixture::report(), 'https://example.com/typo3/module/system/context-reports/show?report=CR-7K3Q-9XMA-2B4F', 1, 'id', static fn(): string => 'png-bytes');
    }

    private function createMailer(?\Throwable $failure = null): RecordingMailer
    {
        return new RecordingMailer($failure);
    }
}
