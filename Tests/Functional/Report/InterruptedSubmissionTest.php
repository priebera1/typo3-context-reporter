<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Report;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Domain\DeliveryAttempt;
use Priebera\ContextReporter\Domain\DeliveryState;
use Priebera\ContextReporter\Domain\Repository\DeliveryAttemptRepository;
use Priebera\ContextReporter\Domain\Repository\ReportRepository;
use Priebera\ContextReporter\Report\ReportService;
use Priebera\ContextReporter\Report\ReportSubmission;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * A10 (independent review 2026-11-07): the first request stored the report
 * and ended before it delivered anything (PHP timeout, a killed worker). The
 * editor sends the same dialog again: the stored report is returned and the
 * destinations that have no attempt are delivered now, exactly once; a
 * further repetition delivers nothing. The interrupted request is the stored
 * report without any delivery attempt.
 */
final class InterruptedSubmissionTest extends AbstractContextReporterTestCase
{
    private string $mailbox = '';

    /**
     * @var list<RequestInterface>
     */
    private array $webhookRequests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailbox = $this->instancePath . '/typo3temp/var/transient/mailbox.mbox';
        @unlink($this->mailbox);
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = 'mbox';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file'] = $this->mailbox;
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] = 'typo3@example.com';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromName'] = 'TYPO3';
        $requests = &$this->webhookRequests;
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['contextReporterTest'] = static function (callable $next) use (&$requests): \Closure {
            return static function (RequestInterface $request, array $options) use (&$requests) {
                $requests[] = $request;
                return Create::promiseFor(new Response(201, ['Content-Type' => 'application/json'], '{"reference":"SUP-1"}'));
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
    public function aSubmissionRepeatedAfterTheRequestEndedBeforeDeliveringDeliversOnce(): void
    {
        $editor = $this->loginBackendUser(self::EDITOR);
        $request = CollectionRequest::fromArray(['source' => 'contextMenu', 'target' => ['type' => 'page', 'uid' => 2]], new BackendLocationFactory());
        $service = $this->get(ReportService::class);
        $prepared = $service->prepare($request, $editor, $GLOBALS['TYPO3_REQUEST']);
        // The interrupted request: stored, nothing delivered
        $stored = $service->submit(new ReportSubmission($prepared->draftToken, 'Teaser is broken', '', null, false), $editor);
        self::assertSame([], $stored->attempts);
        self::assertSame([], $this->webhookRequests);

        $again = $service->submit(new ReportSubmission($prepared->draftToken, 'Teaser is broken', '', null, true), $editor);
        $third = $service->submit(new ReportSubmission($prepared->draftToken, 'Teaser is broken', '', null, true), $editor);

        foreach ([$again, $third] as $result) {
            self::assertTrue($result->replayed);
            self::assertSame($stored->report->identifier, $result->report->identifier);
            self::assertSame(['email', 'webhook'], array_map(static fn(DeliveryAttempt $a): string => $a->destination, $result->attempts));
            self::assertSame(DeliveryState::Delivered, $result->getDeliveryState());
        }
        self::assertSame(1, (int)$this->get(ConnectionPool::class)->getConnectionForTable(ReportRepository::TABLE)->count('*', ReportRepository::TABLE, []));
        self::assertCount(1, $this->webhookRequests, 'Delivered once');
        self::assertSame(1, substr_count((string)file_get_contents($this->mailbox), 'Subject: [Functional Test Portal] Teaser is broken'), 'One email');
        self::assertCount(2, $this->get(DeliveryAttemptRepository::class)->findByReportUid((int)$stored->report->uid));
    }
}
