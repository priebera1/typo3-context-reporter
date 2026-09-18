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
use Priebera\ContextReporter\Domain\DeliveryState;
use Priebera\ContextReporter\Domain\DeliveryStatus;
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
