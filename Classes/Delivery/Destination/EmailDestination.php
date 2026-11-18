<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Delivery\Destination;

use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Priebera\ContextReporter\Configuration\WebhookSettings;
use Priebera\ContextReporter\Delivery\DeliveryOutcome;
use Priebera\ContextReporter\Delivery\DeliveryRequest;
use Priebera\ContextReporter\Delivery\DestinationInterface;
use Priebera\ContextReporter\Delivery\Email\EmailTemplateLoader;
use Priebera\ContextReporter\Delivery\Email\MailConfigurationInspector;
use Priebera\ContextReporter\Delivery\Email\ReportEmailHtmlRenderer;
use Priebera\ContextReporter\Delivery\Email\ReportEmailModel;
use Priebera\ContextReporter\Delivery\SafeErrorMessage;
use Priebera\ContextReporter\Domain\DeliveryStatus;
use Priebera\ContextReporter\Export\ContextDetailsFormatter;
use Priebera\ContextReporter\Export\JsonReportExporter;
use Priebera\ContextReporter\Export\ReportMarkers;
use Priebera\ContextReporter\Export\ReportPayloadFactory;
use Priebera\ContextReporter\Hub\HubAcknowledgement;
use Priebera\ContextReporter\Template\MarkerRenderer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Sends the report email through the TYPO3 mail API: the plain-text part from
 * the configurable marker template, and, unless switched off, a fixed HTML part
 * rendered from the same facts. The screenshot and the JSON report are
 * attached when enabled, and both parts only mention what is attached. The
 * Hub reference is named once the Hub acknowledged the report (the Hub is
 * delivered first; without its acknowledgement the email is sent anyway).
 * Transport errors are stored without the mail credentials of the TYPO3
 * configuration.
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
        private ContextDetailsFormatter $detailsFormatter,
        private ReportEmailHtmlRenderer $htmlRenderer,
        private ?LoggerInterface $logger = null,
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
        $allSettings = $this->settingsProvider->get();
        $settings = $allSettings->email;
        $report = $request->report;
        try {
            $payload = $this->payloadFactory->create($report, $request->reportUrl);
            $markers = $this->markers->fromPayload($payload);

            $reporterEmail = $markers['reporter.email'];
            $replyTo = $settings->replyToReporter && $reporterEmail !== '' && GeneralUtility::validEmail($reporterEmail);
            $screenshot = null;
            if ($settings->attachScreenshot && $report->screenshot !== null) {
                $content = $request->getScreenshotContent();
                if ($content !== null) {
                    $screenshot = ['metadata' => $report->screenshot, 'content' => $content];
                }
            }
            $model = ReportEmailModel::create(
                $payload,
                $this->detailsFormatter->buildFindings($payload),
                $this->detailsFormatter->buildSections($payload),
                $this->hubReceipt($request, $allSettings->hub),
                $screenshot !== null ? [
                    'filename' => $screenshot['metadata']->filename,
                    'mediaType' => $screenshot['metadata']->mediaType,
                    'size' => $screenshot['metadata']->size,
                    'width' => $screenshot['metadata']->width,
                    'height' => $screenshot['metadata']->height,
                ] : null,
                $settings->attachJson,
                $replyTo ? $markers['reporter.name'] : '',
                date_default_timezone_get(),
            );
            $markers = $model['markers'] + $markers;

            $subject = $this->renderer->render($settings->subjectTemplate, $markers, singleLine: true);
            if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
                $subject = mb_substr($subject, 0, self::MAX_SUBJECT_LENGTH - 1) . '…';
            }
            $model['subjectLine'] = $subject;

            $message = (new MailMessage())
                ->to(...array_map(static fn(string $recipient): Address => new Address($recipient), $settings->recipients))
                ->subject($subject)
                ->text($this->renderer->render($this->templateLoader->load($settings->bodyTemplateFile), $markers));
            $htmlFailed = false;
            // Only with the shipped template: a custom template decides the content of the whole email
            if ($settings->sendsHtmlVersion()) {
                try {
                    $message->html($this->htmlRenderer->render($model, $markers['context.details']));
                } catch (\Throwable) {
                    // The plain text carries the same report: an HTML problem never costs the email
                    $htmlFailed = true;
                }
            }
            $message->getHeaders()->addTextHeader('X-Context-Reporter-Report', $report->identifier);

            if ($settings->senderAddress !== '') {
                $message->from(new Address($settings->senderAddress, $settings->senderName));
            }
            if ($replyTo) {
                $message->replyTo(new Address($reporterEmail, $markers['reporter.name']));
            }
            if ($screenshot !== null) {
                $message->attach($screenshot['content'], $screenshot['metadata']->filename, $screenshot['metadata']->mediaType);
            }
            if ($settings->attachJson) {
                $message->attach($this->jsonExporter->export($payload), $report->identifier . '.json', 'application/json');
            }

            $this->mailer->send($message);
        } catch (\Throwable $exception) {
            $this->logger?->warning('Report email could not be sent: {error}', SafeErrorMessage::logContext($exception, $this->mailConfiguration->getSecrets()));
            return DeliveryOutcome::failure($exception instanceof TransportExceptionInterface
                ? SafeErrorMessage::describeTransportFailure($exception)
                : SafeErrorMessage::fromThrowable($exception, $this->mailConfiguration->getSecrets()));
        }

        $count = count($settings->recipients);
        return DeliveryOutcome::success(
            'Email handed over to the mail transport for ' . $count . ($count === 1 ? ' recipient.' : ' recipients.')
            . ($htmlFailed ? ' The HTML version could not be rendered; the email was sent as plain text.' : ''),
        );
    }

    /**
     * The reference of the Hub when it acknowledged this report, with its link
     * only on the trusted Hub address configured now.
     *
     * @return array{reference: string, url: string}|null
     */
    private function hubReceipt(DeliveryRequest $request, WebhookSettings $hub): ?array
    {
        $attempt = $request->priorAttempts[HubDestination::IDENTIFIER] ?? null;
        if ($attempt === null || $attempt->status !== DeliveryStatus::Succeeded || $attempt->externalReference === '') {
            return null;
        }
        return ['reference' => $attempt->externalReference, 'url' => HubAcknowledgement::trustedLink($attempt->externalUrl, $hub)];
    }
}
