<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Settings;

use Priebera\ContextReporter\Configuration\ExtensionInfo;
use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Priebera\ContextReporter\Configuration\HubIntake;
use Priebera\ContextReporter\Configuration\WebhookSettings;
use Priebera\ContextReporter\Delivery\DeliveryId;
use Priebera\ContextReporter\Delivery\DeliveryOutcome;
use Priebera\ContextReporter\Delivery\Email\MailConfigurationInspector;
use Priebera\ContextReporter\Delivery\SafeErrorMessage;
use Priebera\ContextReporter\Delivery\Webhook\WebhookClient;
use Priebera\ContextReporter\Export\JsonReportExporter;
use Priebera\ContextReporter\Export\ReportPayloadFactory;
use Priebera\ContextReporter\Hub\HubAcknowledgement;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Mail\MailMessage;

/**
 * Sends a test email, test webhook or Hub connection test with the saved
 * configuration, also while the destination is switched off. Tests contain
 * no report data and are not recorded in the delivery history.
 *
 * @internal
 */
final readonly class DestinationTester
{
    public const WEBHOOK_EVENT = 'test';

    public function __construct(
        private ExtensionSettingsProvider $settingsProvider,
        private MailerInterface $mailer,
        private MailConfigurationInspector $mailConfiguration,
        private WebhookClient $webhookClient,
        private JsonReportExporter $jsonExporter,
        private ExtensionInfo $extensionInfo,
    ) {}

    public function sendTestEmail(): DeliveryOutcome
    {
        $settings = $this->settingsProvider->get();
        $email = $settings->email;
        if ($email->recipients === []) {
            return DeliveryOutcome::failure('No email recipients are configured.');
        }

        $lines = [
            'This is a test email from TYPO3 Context Reporter.',
            '',
            'It was sent to the recipients of problem reports:',
            implode(', ', $email->recipients),
            '',
            'Project: ' . $settings->projectName . ($settings->environment !== '' ? ' (' . $settings->environment . ')' : ''),
            'Sent: ' . gmdate('Y-m-d H:i') . ' UTC',
            '',
            $email->enabled
                ? 'Email delivery of reports is switched on.'
                : 'Email delivery of reports is switched off. Switch it on in System > Context Reports > Settings to receive reports.',
        ];

        try {
            $message = (new MailMessage())
                ->to(...array_map(static fn(string $recipient): Address => new Address($recipient), $email->recipients))
                ->subject('[' . $settings->projectName . '] Context Reporter test email')
                ->text(implode("\n", $lines) . "\n");
            $message->getHeaders()->addTextHeader('X-Context-Reporter-Test', '1');
            if ($email->senderAddress !== '') {
                $message->from(new Address($email->senderAddress, $email->senderName));
            }
            $this->mailer->send($message);
        } catch (\Throwable $exception) {
            return DeliveryOutcome::failure(SafeErrorMessage::fromThrowable($exception, $this->mailConfiguration->getSecrets()));
        }

        $count = count($email->recipients);
        return DeliveryOutcome::success('Test email handed over to the mail transport for ' . $count . ($count === 1 ? ' recipient.' : ' recipients.'));
    }

    public function sendTestWebhook(): DeliveryOutcome
    {
        $webhook = $this->settingsProvider->get()->webhook;
        if ($webhook->url === '') {
            return DeliveryOutcome::failure('No usable webhook endpoint is configured.');
        }
        $body = $this->buildTestBody($webhook);
        if ($body instanceof DeliveryOutcome) {
            return $body;
        }
        if (HubIntake::isIntakeUrl($webhook->url)) {
            // A webhook to a Hub intake address delivers only with the Hub's acknowledgement: its test needs the Hub's confirmation too
            return HubAcknowledgement::testOutcome($this->webhookClient->exchange($webhook, self::WEBHOOK_EVENT, $body['body'], $body['headers']), $webhook);
        }
        return $this->webhookClient->post($webhook, self::WEBHOOK_EVENT, $body['body'], $body['headers']);
    }

    /**
     * The same test delivery as the webhook test, to the saved Hub site. The
     * Hub marks the site as connected and creates nothing.
     */
    public function sendTestToHub(): DeliveryOutcome
    {
        $hub = $this->settingsProvider->get()->hub;
        if ($hub->url === '') {
            return DeliveryOutcome::failure('No usable Context Reporter Hub webhook URL is configured.');
        }
        if ($hub->secret === '') {
            return DeliveryOutcome::failure('No usable Context Reporter Hub signing secret is configured.');
        }
        $body = $this->buildTestBody($hub);
        if ($body instanceof DeliveryOutcome) {
            return $body;
        }
        // Connected only when the Hub confirms the test, not with any 2xx (e.g. a login page)
        return HubAcknowledgement::testOutcome($this->webhookClient->exchange($hub, self::WEBHOOK_EVENT, $body['body'], $body['headers']), $hub);
    }

    /**
     * @return array{body: string, headers: array<string, string>}|DeliveryOutcome
     */
    private function buildTestBody(WebhookSettings $target): array|DeliveryOutcome
    {
        $settings = $this->settingsProvider->get();
        $deliveryId = DeliveryId::create();
        $project = ['name' => $settings->projectName];
        if ($settings->projectIdentifier !== '') {
            $project['identifier'] = $settings->projectIdentifier;
        }
        $project['environment'] = $settings->environment;
        try {
            $body = $this->jsonExporter->export([
                'event' => self::WEBHOOK_EVENT,
                'delivery' => [
                    'id' => $deliveryId,
                    'attempt' => 1,
                    'sentAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ],
                'test' => [
                    'message' => 'Test delivery from TYPO3 Context Reporter. It does not contain a report.',
                    'project' => $project,
                    'reportSchema' => ReportPayloadFactory::SCHEMA,
                ],
                'generator' => [
                    'name' => ExtensionInfo::PRODUCT_NAME,
                    'package' => ExtensionInfo::PACKAGE_NAME,
                    'version' => $this->extensionInfo->getVersion(),
                ],
            ], pretty: false);
        } catch (\Throwable $exception) {
            return DeliveryOutcome::failure(SafeErrorMessage::fromThrowable($exception, $target->getSecrets()));
        }

        return ['body' => $body, 'headers' => ['X-Context-Reporter-Delivery' => $deliveryId]];
    }
}
