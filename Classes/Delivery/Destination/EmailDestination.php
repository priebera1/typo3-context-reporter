<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery\Destination;

use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Priebera\ContextReporter\Delivery\DeliveryOutcome;
use Priebera\ContextReporter\Delivery\DeliveryRequest;
use Priebera\ContextReporter\Delivery\DestinationInterface;
use Priebera\ContextReporter\Delivery\Email\EmailTemplateLoader;
use Priebera\ContextReporter\Delivery\Email\MailConfigurationInspector;
use Priebera\ContextReporter\Delivery\SafeErrorMessage;
use Priebera\ContextReporter\Export\JsonReportExporter;
use Priebera\ContextReporter\Export\ReportMarkers;
use Priebera\ContextReporter\Export\ReportPayloadFactory;
use Priebera\ContextReporter\Template\MarkerRenderer;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Sends a plain text email through the TYPO3 mail API. The screenshot and the
 * JSON report are attached when enabled. Transport errors are stored without
 * the mail credentials of the TYPO3 configuration.
 *
 * @internal
 */
#[AsTaggedItem(priority: 20)]
final readonly class EmailDestination implements DestinationInterface
{
    public const IDENTIFIER = 'email';
    private const MAX_SUBJECT_LENGTH = 200;

    public function __construct(
        private ExtensionSettingsProvider $settingsProvider,
        private MailerInterface $mailer,
        private ReportPayloadFactory $payloadFactory,
        private JsonReportExporter $jsonExporter,
        private ReportMarkers $markers,
        private MarkerRenderer $renderer,
        private EmailTemplateLoader $templateLoader,
        private MailConfigurationInspector $mailConfiguration,
    ) {}

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function isEnabled(): bool
    {
        return $this->settingsProvider->get()->email->isUsable();
    }

    public function describeTarget(): string
    {
        $count = count($this->settingsProvider->get()->email->recipients);
        return $count === 1 ? '1 recipient' : $count . ' recipients';
    }

    public function deliver(DeliveryRequest $request): DeliveryOutcome
    {
        $settings = $this->settingsProvider->get()->email;
        $report = $request->report;
        try {
            $payload = $this->payloadFactory->create($report, $request->reportUrl);
            $markers = $this->markers->fromPayload($payload);
            $subject = $this->renderer->render($settings->subjectTemplate, $markers, singleLine: true);
            if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
                $subject = mb_substr($subject, 0, self::MAX_SUBJECT_LENGTH - 1) . '…';
            }

            $message = (new MailMessage())
                ->to(...array_map(static fn(string $recipient): Address => new Address($recipient), $settings->recipients))
                ->subject($subject)
                ->text($this->renderer->render($this->templateLoader->load($settings->bodyTemplateFile), $markers));
            $message->getHeaders()->addTextHeader('X-Context-Reporter-Report', $report->identifier);

            if ($settings->senderAddress !== '') {
                $message->from(new Address($settings->senderAddress, $settings->senderName));
            }
            $reporterEmail = $markers['reporter.email'];
            if ($settings->replyToReporter && $reporterEmail !== '' && GeneralUtility::validEmail($reporterEmail)) {
                $message->replyTo(new Address($reporterEmail, $markers['reporter.name']));
            }
            if ($settings->attachScreenshot && $report->screenshot !== null) {
                $screenshot = $request->getScreenshotContent();
                if ($screenshot !== null) {
                    $message->attach($screenshot, $report->screenshot->filename, $report->screenshot->mediaType);
                }
            }
            if ($settings->attachJson) {
                $message->attach($this->jsonExporter->export($payload), $report->identifier . '.json', 'application/json');
            }

            $this->mailer->send($message);
        } catch (\Throwable $exception) {
            return DeliveryOutcome::failure(SafeErrorMessage::fromThrowable($exception, $this->mailConfiguration->getSecrets()));
        }

        $count = count($settings->recipients);
        return DeliveryOutcome::success('Email handed over to the mail transport for ' . $count . ($count === 1 ? ' recipient.' : ' recipients.'));
    }
}
