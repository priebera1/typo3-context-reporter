<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Report;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Delivery\DeliveryNotPossibleException;
use Priebera\ContextReporter\Delivery\DeliveryService;
use Priebera\ContextReporter\Delivery\Webhook\WebhookSignature;
use Priebera\ContextReporter\Domain\DeliveryAttempt;
use Priebera\ContextReporter\Domain\DeliveryState;
use Priebera\ContextReporter\Domain\DeliveryStatus;
use Priebera\ContextReporter\Domain\Report;
use Priebera\ContextReporter\Domain\ReportSource;
use Priebera\ContextReporter\Domain\Repository\DeliveryAttemptRepository;
use Priebera\ContextReporter\Domain\Repository\ReportRepository;
use Priebera\ContextReporter\Report\InvalidReportInputException;
use Priebera\ContextReporter\Report\InvalidScreenshotException;
use Priebera\ContextReporter\Report\RateLimitExceededException;
use Priebera\ContextReporter\Report\ReportIdGenerator;
use Priebera\ContextReporter\Report\ReportService;
use Priebera\ContextReporter\Report\ReportSubmission;
use Priebera\ContextReporter\Security\InvalidDraftTokenException;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class ReportServiceTest extends AbstractContextReporterTestCase
{
    private const SCREENSHOT = __DIR__ . '/../../Unit/Fixtures/Images/screenshot.png';
    private const HUB_URL = 'https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P';
    private const HUB_SECRET = 'whsec_8Fq2LmZx4RtY7vB1nKc3Wd9PaQ5sT0uV';

    private string $mailbox = '';

    /**
     * @var list<Response>
     */
    private array $webhookResponses = [];

    /**
     * @var list<RequestInterface>
     */
    private array $webhookRequests = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The test instance is shared by all tests of this class
        $this->mailbox = $this->instancePath . '/typo3temp/var/transient/mailbox.mbox';
        @unlink($this->mailbox);
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = 'mbox';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file'] = $this->mailbox;
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] = 'typo3@example.com';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromName'] = 'TYPO3';

        $requests = &$this->webhookRequests;
        $responses = &$this->webhookResponses;
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['contextReporterTest'] = static function (callable $next) use (&$requests, &$responses): \Closure {
            return static function (RequestInterface $request, array $options) use (&$requests, &$responses) {
                $requests[] = $request;
                return Create::promiseFor(array_shift($responses) ?? new Response(500));
            };
        };

        $this->configureExtension([
            'email' => ['enabled' => '1', 'recipients' => 'support@example.com', 'subject' => '[{project.name}] {report.title}'],
            'webhook' => ['enabled' => '1', 'url' => 'https://hooks.example.com/report', 'secret' => 'webhook-secret'],
        ]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['contextReporterTest']);
        parent::tearDown();
    }

    #[Test]
    public function reviewedContextIsStoredDeliveredAndFailedDeliveriesCanBeRetried(): void
    {
        $editor = $this->loginBackendUser(self::EDITOR);
        $this->webhookResponses = [new Response(500, [], 'Temporary outage')];

        $prepared = $this->prepare($editor, ['type' => 'record', 'table' => 'tt_content', 'uid' => 10]);
        $result = $this->get(ReportService::class)->submit(
            new ReportSubmission($prepared->draftToken, "  Image selector\nis empty ", "Steps:\r\n1. Open the element", (string)file_get_contents(self::SCREENSHOT), true),
            $editor,
        );

        $report = $result->report;
        self::assertTrue(ReportIdGenerator::isValid($report->identifier));
        self::assertSame('Image selector is empty', $report->title);
        self::assertSame("Steps:\n1. Open the element", $report->description);
        self::assertSame($prepared->document->toArray(), $report->document->toArray());
        // The visibility settings the reporter reviewed are part of the sealed context
        self::assertSame(['reasons' => []], $report->document->getContextSection('visibility')['subject'] ?? null);
        self::assertSame(DeliveryState::Partial, $result->getDeliveryState());

        $stored = $this->get(ReportRepository::class)->findByIdentifier($report->identifier);
        self::assertNotNull($stored);
        self::assertSame(DeliveryState::Partial, $stored->deliveryState);
        self::assertSame(self::EDITOR, $stored->reporterUid);
        self::assertSame($prepared->document->toArray(), $stored->document->toArray());
        self::assertSame(file_get_contents(self::SCREENSHOT), $this->get(ReportRepository::class)->findScreenshotContent($stored->uid));

        [$email, $webhook] = $result->attempts;
        self::assertSame('email', $email->destination);
        self::assertSame(DeliveryStatus::Succeeded, $email->status);
        self::assertSame('1 recipient', $email->target);
        $mail = (string)file_get_contents($this->mailbox);
        self::assertStringContainsString('Subject: [Functional Test Portal] Image selector is empty', $mail);
        self::assertStringContainsString($report->identifier . '-screenshot.png', $mail);

        self::assertSame('webhook', $webhook->destination);
        self::assertSame(DeliveryStatus::Failed, $webhook->status);
        self::assertSame(500, $webhook->responseCode);
        self::assertSame('HTTP 500: Temporary outage', $webhook->message);
        self::assertSame('https://hooks.example.com', $webhook->target);

        $sent = $this->webhookRequests[0];
        $body = (string)$sent->getBody();
        self::assertTrue(WebhookSignature::verify($sent->getHeaderLine(WebhookSignature::HEADER), 'webhook-secret', $body));
        $envelope = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($report->identifier, $envelope['report']['id']);
        self::assertSame($report->document->getContextSection('visibility'), $envelope['report']['context']['visibility']);
        self::assertSame(base64_encode((string)file_get_contents(self::SCREENSHOT)), $envelope['report']['attachments'][0]['contentBase64']);
        self::assertStringContainsString('/typo3/module/system/context-reports/show?report=' . $report->identifier, $envelope['report']['links']['report']);

        $this->webhookResponses = [new Response(201, ['Content-Type' => 'application/json'], '{"reference":"SUP-7","url":"https://desk.example.com/7"}')];
        $this->loginBackendUser(self::ADMIN);
        $retry = $this->get(DeliveryService::class)->retry($stored, 'webhook', self::ADMIN);

        self::assertSame(DeliveryStatus::Succeeded, $retry->status);
        self::assertSame(2, $retry->attempt);
        self::assertSame(self::ADMIN, $retry->triggeredBy);
        self::assertSame('SUP-7', $retry->externalReference);
        self::assertSame('https://desk.example.com/7', $retry->externalUrl);
        self::assertSame(DeliveryState::Delivered, $this->get(ReportRepository::class)->findByIdentifier($report->identifier)?->deliveryState);
        self::assertCount(3, $this->get(DeliveryAttemptRepository::class)->findByReportUid($stored->uid));

        $this->expectException(DeliveryNotPossibleException::class);
        $this->get(DeliveryService::class)->retry($stored, 'webhook', self::ADMIN);
    }

    #[Test]
    public function hubAndWebhookReceiveTheSameSignedReportAndTheHubReferenceIsKept(): void
    {
        $this->configureExtension([
            'webhook' => ['enabled' => '1', 'url' => 'https://hooks.example.com/report', 'secret' => 'webhook-secret'],
            'hub' => ['enabled' => '1', 'url' => self::HUB_URL, 'secret' => self::HUB_SECRET],
        ]);
        $editor = $this->loginBackendUser(self::EDITOR);
        // The Hub rejects the first delivery (secret rotated in the Hub), the webhook accepts
        $this->webhookResponses = [
            new Response(401, ['Content-Type' => 'application/json; charset=utf-8'], '{"error":"invalid_signature","message":"The signature does not match."}'),
            new Response(201, ['Content-Type' => 'application/json'], '{"reference":"SUP-9","url":"https://desk.example.com/9"}'),
        ];

        $prepared = $this->prepare($editor, ['type' => 'record', 'table' => 'tt_content', 'uid' => 10]);
        $result = $this->get(ReportService::class)->submit(new ReportSubmission($prepared->draftToken, 'Hub report', '', (string)file_get_contents(self::SCREENSHOT), true), $editor);

        $report = $result->report;
        self::assertSame(['hub', 'webhook'], array_map(static fn(DeliveryAttempt $attempt): string => $attempt->destination, $result->attempts));
        [$hub, $webhook] = $result->attempts;
        self::assertSame(DeliveryStatus::Failed, $hub->status);
        self::assertSame(401, $hub->responseCode);
        self::assertSame('https://hub.example.com', $hub->target);
        self::assertSame('SUP-9', $webhook->externalReference);
        self::assertSame(DeliveryState::Partial, $result->getDeliveryState());

        [$toHub, $toWebhook] = $this->webhookRequests;
        self::assertSame(self::HUB_URL, (string)$toHub->getUri());
        self::assertTrue(WebhookSignature::verify($toHub->getHeaderLine(WebhookSignature::HEADER), self::HUB_SECRET, (string)$toHub->getBody()));
        self::assertTrue(WebhookSignature::verify($toWebhook->getHeaderLine(WebhookSignature::HEADER), 'webhook-secret', (string)$toWebhook->getBody()));
        $hubPayload = json_decode((string)$toHub->getBody(), true, 512, JSON_THROW_ON_ERROR)['report'];
        self::assertSame(json_decode((string)$toWebhook->getBody(), true, 512, JSON_THROW_ON_ERROR)['report'], $hubPayload);
        self::assertSame($report->identifier, $hubPayload['id']);
        self::assertSame(base64_encode((string)file_get_contents(self::SCREENSHOT)), $hubPayload['attachments'][0]['contentBase64']);

        // A retry is the same report ID: the Hub creates the report once and answers its reference
        $this->webhookResponses = [new Response(200, ['Content-Type' => 'application/json; charset=utf-8'], '{"reference":"LW-152","url":"https://hub.example.com/app/reports/LW-152"}')];
        $this->loginBackendUser(self::ADMIN);
        $stored = $this->get(ReportRepository::class)->findByIdentifier($report->identifier);
        self::assertNotNull($stored);
        $retry = $this->get(DeliveryService::class)->retry($stored, 'hub', self::ADMIN);

        self::assertSame(DeliveryStatus::Succeeded, $retry->status);
        self::assertSame(2, $retry->attempt);
        self::assertSame('LW-152', $retry->externalReference);
        self::assertSame('https://hub.example.com/app/reports/LW-152', $retry->externalUrl);
        self::assertSame($report->identifier, $this->webhookRequests[2]->getHeaderLine('X-Context-Reporter-Report'));
        self::assertSame('2', $this->webhookRequests[2]->getHeaderLine('X-Context-Reporter-Attempt'));
        self::assertSame(DeliveryState::Delivered, $this->get(ReportRepository::class)->findByIdentifier($report->identifier)?->deliveryState);
        $attemptRows = json_encode($this->get(ConnectionPool::class)->getConnectionForTable(DeliveryAttemptRepository::TABLE)->select(['*'], DeliveryAttemptRepository::TABLE)->fetchAllAssociative(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::HUB_SECRET, $attemptRows);
        self::assertStringNotContainsString('s_8Fq2LmZx4RtY7vB1nKc3Wd9P', $attemptRows);
    }

    #[Test]
    public function fileChecksAreSealedStoredAndDelivered(): void
    {
        $admin = $this->loginBackendUser(self::ADMIN);
        $this->webhookResponses = [new Response(201, ['Content-Type' => 'application/json'], '{"reference":"SUP-8"}')];

        // manual.pdf is marked as missing and not in the storage
        $prepared = $this->prepare($admin, ['type' => 'file', 'uid' => self::FILE_MISSING]);
        $checks = ['file' => ['storageCheck' => 'notFound', 'problems' => ['missing', 'notInStorage']]];
        self::assertSame($checks, $prepared->document->getContextSection('fileChecks'));

        $report = $this->get(ReportService::class)->submit(new ReportSubmission($prepared->draftToken, 'Manual cannot be downloaded', '', null, true), $admin)->report;

        self::assertSame($checks, $this->get(ReportRepository::class)->findByIdentifier($report->identifier)?->document->getContextSection('fileChecks'));
        // The text part of the email is quoted-printable
        $mail = str_replace("\r\n", "\n", quoted_printable_decode((string)file_get_contents($this->mailbox)));
        self::assertStringContainsString("File checks\n  Reported file: not found in its storage; marked as missing\n", $mail);
        self::assertStringContainsString("FINDINGS\nFiles\n  - Not found in its storage\n  - No file references to this file (links in texts are not counted)\n\nThese are settings", $mail);
        $envelope = json_decode((string)$this->webhookRequests[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($checks, $envelope['report']['context']['fileChecks']);
    }

    #[Test]
    public function downloadOnlyStoresTheReportWithoutDelivering(): void
    {
        $editor = $this->loginBackendUser(self::EDITOR);
        $prepared = $this->prepare($editor, ['type' => 'page', 'uid' => 2]);

        $result = $this->get(ReportService::class)->submit(new ReportSubmission($prepared->draftToken, 'Title', '', null, false), $editor);

        self::assertSame([], $result->attempts);
        self::assertSame(DeliveryState::Local, $result->getDeliveryState());
        self::assertNull($result->report->screenshot);
        self::assertSame([], $this->webhookRequests);
        self::assertSame('', (string)@file_get_contents($this->mailbox));
    }

    #[Test]
    public function reportsWithoutEnabledDestinationsStayLocal(): void
    {
        $this->configureExtension([]);
        $editor = $this->loginBackendUser(self::EDITOR);
        $prepared = $this->prepare($editor, ['type' => 'page', 'uid' => 2]);

        $result = $this->get(ReportService::class)->submit(new ReportSubmission($prepared->draftToken, 'Title', '', null, true), $editor);

        self::assertSame([], $result->attempts);
        self::assertSame(DeliveryState::Local, $this->get(ReportRepository::class)->findByIdentifier($result->report->identifier)?->deliveryState);
    }

    #[Test]
    public function draftOfAnotherUserIsRejected(): void
    {
        $colleague = $this->loginBackendUser(self::COLLEAGUE);
        $prepared = $this->prepare($colleague, ['type' => 'page', 'uid' => 2]);
        $editor = $this->loginBackendUser(self::EDITOR);

        $this->expectException(InvalidDraftTokenException::class);
        $this->get(ReportService::class)->submit(new ReportSubmission($prepared->draftToken, 'Title', '', null, true), $editor);
    }

    #[Test]
    public function tamperedDraftIsRejectedAndNothingIsStored(): void
    {
        $editor = $this->loginBackendUser(self::EDITOR);
        $prepared = $this->prepare($editor, ['type' => 'page', 'uid' => 2]);
        $tampered = substr($prepared->draftToken, 0, 10) . 'X' . substr($prepared->draftToken, 11);

        try {
            $this->get(ReportService::class)->submit(new ReportSubmission($tampered, 'Title', '', null, true), $editor);
            self::fail('Expected exception was not thrown.');
        } catch (InvalidDraftTokenException) {
            self::assertSame(0, $this->countReports());
        }
    }

    #[Test]
    public function invalidInputIsRejectedBeforeAnythingIsStored(): void
    {
        $editor = $this->loginBackendUser(self::EDITOR);
        $prepared = $this->prepare($editor, ['type' => 'page', 'uid' => 2]);
        $service = $this->get(ReportService::class);

        try {
            $service->submit(new ReportSubmission($prepared->draftToken, '   ', '', null, true), $editor);
            self::fail('Expected exception was not thrown.');
        } catch (InvalidReportInputException $exception) {
            self::assertSame(InvalidReportInputException::TITLE_MISSING, $exception->getReason());
        }
        try {
            $service->submit(new ReportSubmission($prepared->draftToken, 'Title', '', '<svg onload="alert(1)"/>', true), $editor);
            self::fail('Expected exception was not thrown.');
        } catch (InvalidScreenshotException) {
            self::assertSame(0, $this->countReports());
        }
    }

    #[Test]
    public function reportsPerUserAreRateLimited(): void
    {
        $this->configureExtension(['reporting' => ['maxReportsPerUserPerHour' => '1']]);
        $editor = $this->loginBackendUser(self::EDITOR);
        $service = $this->get(ReportService::class);
        $service->submit(new ReportSubmission($this->prepare($editor, ['type' => 'page', 'uid' => 2])->draftToken, 'First', '', null, true), $editor);

        $this->expectException(RateLimitExceededException::class);
        $service->submit(new ReportSubmission($this->prepare($editor, ['type' => 'page', 'uid' => 2])->draftToken, 'Second', '', null, true), $editor);
    }

    /**
     * K5: the answer to a submission can be lost (network, proxy, closed laptop)
     * after the report was stored and delivered. Sending the same prepared report
     * again returns the stored report: no second report, no second delivery.
     */
    #[Test]
    public function aRepeatedSubmissionReturnsTheStoredReportWithoutDeliveringAgain(): void
    {
        $editor = $this->loginBackendUser(self::EDITOR);
        $this->webhookResponses = [new Response(201, ['Content-Type' => 'application/json'], '{"reference":"SUP-1"}')];
        $prepared = $this->prepare($editor, ['type' => 'page', 'uid' => 2]);
        $service = $this->get(ReportService::class);

        $first = $service->submit(new ReportSubmission($prepared->draftToken, 'Teaser is broken', '', (string)file_get_contents(self::SCREENSHOT), true), $editor);
        $again = $service->submit(new ReportSubmission($prepared->draftToken, 'Teaser is broken', '', (string)file_get_contents(self::SCREENSHOT), true), $editor);
        $edited = $service->submit(new ReportSubmission($prepared->draftToken, 'Edited after the error', '', null, false), $editor);

        self::assertTrue(ReportIdGenerator::isValid($prepared->reportIdentifier));
        self::assertSame($prepared->reportIdentifier, $first->report->identifier, 'The identifier is reserved when the dialog opens');
        self::assertFalse($first->replayed);
        foreach ([$again, $edited] as $result) {
            self::assertTrue($result->replayed);
            self::assertSame($first->report->identifier, $result->report->identifier);
            self::assertSame('Teaser is broken', $result->report->title, 'The stored report wins');
            self::assertSame(['email', 'webhook'], array_map(static fn(DeliveryAttempt $attempt): string => $attempt->destination, $result->attempts));
            self::assertSame(DeliveryState::Delivered, $result->getDeliveryState());
        }
        self::assertSame(1, $this->countReports());
        self::assertCount(1, $this->webhookRequests, 'Delivered once');
        self::assertSame(1, substr_count((string)file_get_contents($this->mailbox), 'Subject: [Functional Test Portal] Teaser is broken'), 'One email');
        self::assertCount(2, $this->get(DeliveryAttemptRepository::class)->findByReportUid((int)$first->report->uid));

        // A new dialog is a new report
        $next = $service->submit(new ReportSubmission($this->prepare($editor, ['type' => 'page', 'uid' => 2])->draftToken, 'Another problem', '', null, false), $editor);
        self::assertNotSame($first->report->identifier, $next->report->identifier);
        self::assertSame(2, $this->countReports());
    }

    #[Test]
    public function aRepeatedSubmissionIsNotRateLimited(): void
    {
        $this->configureExtension(['reporting' => ['maxReportsPerUserPerHour' => '1']]);
        $editor = $this->loginBackendUser(self::EDITOR);
        $token = $this->prepare($editor, ['type' => 'page', 'uid' => 2])->draftToken;
        $service = $this->get(ReportService::class);

        $first = $service->submit(new ReportSubmission($token, 'First', '', null, true), $editor);
        $again = $service->submit(new ReportSubmission($token, 'First', '', null, true), $editor);

        self::assertSame($first->report->identifier, $again->report->identifier);
        self::assertSame(1, $this->countReports());
    }

    /**
     * The reserved identifier is only a suggestion: when another user's report
     * has it, the report gets a new one and the other report is never returned.
     */
    #[Test]
    public function aReportOfAnotherUserIsNeverReturned(): void
    {
        $this->configureExtension([]);
        $editor = $this->loginBackendUser(self::EDITOR);
        $prepared = $this->prepare($editor, ['type' => 'page', 'uid' => 2]);
        $colleague = $this->loginBackendUser(self::COLLEAGUE);
        $colleaguePrepared = $this->prepare($colleague, ['type' => 'page', 'uid' => 2]);
        $this->get(ReportRepository::class)->add(new Report(
            identifier: $prepared->reportIdentifier,
            createdAt: new \DateTimeImmutable(),
            reporterUid: self::COLLEAGUE,
            source: ReportSource::Toolbar,
            title: 'The colleague\'s report',
            description: '',
            document: $colleaguePrepared->document,
        ));

        $editor = $this->loginBackendUser(self::EDITOR);
        $result = $this->get(ReportService::class)->submit(new ReportSubmission($prepared->draftToken, 'My report', '', null, true), $editor);

        self::assertFalse($result->replayed);
        self::assertNotSame($prepared->reportIdentifier, $result->report->identifier);
        self::assertSame('My report', $result->report->title);
        self::assertSame(self::EDITOR, $result->report->reporterUid);
        self::assertSame(self::COLLEAGUE, $this->get(ReportRepository::class)->findByIdentifier($prepared->reportIdentifier)?->reporterUid);
        self::assertSame(2, $this->countReports());
    }

    /**
     * @param array<string, mixed> $target
     */
    private function prepare(BackendUserAuthentication $backendUser, array $target): \Priebera\ContextReporter\Report\PreparedReport
    {
        $request = CollectionRequest::fromArray(['source' => 'contextMenu', 'target' => $target], new BackendLocationFactory());
        return $this->get(ReportService::class)->prepare($request, $backendUser, $GLOBALS['TYPO3_REQUEST']);
    }

    private function countReports(): int
    {
        return (int)$this->get(ConnectionPool::class)->getConnectionForTable(ReportRepository::TABLE)->count('*', ReportRepository::TABLE, []);
    }
}
