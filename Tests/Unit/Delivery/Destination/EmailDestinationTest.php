<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Delivery\Destination;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\ExtensionInfo;
use Priebera\ContextReporter\Delivery\DeliveryRequest;
use Priebera\ContextReporter\Delivery\Destination\EmailDestination;
use Priebera\ContextReporter\Delivery\Email\EmailTemplateLoader;
use Priebera\ContextReporter\Delivery\Email\MailConfigurationInspector;
use Priebera\ContextReporter\Export\ContextDetailsFormatter;
use Priebera\ContextReporter\Export\JsonReportExporter;
use Priebera\ContextReporter\Export\ReportMarkers;
use Priebera\ContextReporter\Export\ReportPayloadFactory;
use Priebera\ContextReporter\Template\MarkerRenderer;
use Priebera\ContextReporter\Tests\Unit\Fixtures\RecordingMailer;
use Priebera\ContextReporter\Tests\Unit\Fixtures\ReportFixture;
use Priebera\ContextReporter\Tests\Unit\Fixtures\SettingsProviderStub;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class EmailDestinationTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['MAIL']);
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
        self::assertSame('TransportException: Connection could not be established with host "smtp.example.com:465"', $outcome->message);
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
        self::assertStringContainsString('with username "[redacted]"', $outcome->message);
        self::assertStringNotContainsString('agency-mailer', $outcome->message);
        self::assertStringNotContainsString('app-specific-password', $outcome->message);
    }

    #[Test]
    public function targetDescriptionCountsRecipients(): void
    {
        $destination = $this->createDestination($this->createMailer(), ['recipients' => 'a@example.com b@example.com']);

        self::assertSame('2 recipients', $destination->describeTarget());
        self::assertTrue($destination->isEnabled());
    }

    /**
     * @param array<string, string> $emailConfiguration
     */
    private function createDestination(MailerInterface $mailer, array $emailConfiguration, string $bodyTemplate = '{context.details}'): EmailDestination
    {
        $templateLoader = new class ($bodyTemplate) extends EmailTemplateLoader {
            public function __construct(private readonly string $template) {}

            public function load(string $path): string
            {
                return $this->template;
            }
        };
        return new EmailDestination(
            new SettingsProviderStub(['email' => array_merge(['enabled' => '1'], $emailConfiguration)]),
            $mailer,
            new ReportPayloadFactory(new ExtensionInfo('1.0.0')),
            new JsonReportExporter(),
            new ReportMarkers(new ContextDetailsFormatter()),
            new MarkerRenderer(),
            $templateLoader,
            new MailConfigurationInspector(),
        );
    }

    private function createMailer(?\Throwable $failure = null): RecordingMailer
    {
        return new RecordingMailer($failure);
    }
}
