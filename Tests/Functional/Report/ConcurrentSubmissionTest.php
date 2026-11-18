<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Report;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\ContextDocumentBuilder;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Delivery\DeliveryService;
use Priebera\ContextReporter\Domain\Report;
use Priebera\ContextReporter\Domain\Repository\DeliveryAttemptRepository;
use Priebera\ContextReporter\Domain\Repository\ReportRepository;
use Priebera\ContextReporter\Report\ReportIdGenerator;
use Priebera\ContextReporter\Report\ReportService;
use Priebera\ContextReporter\Report\ReportSubmission;
use Priebera\ContextReporter\Report\ScreenshotValidator;
use Priebera\ContextReporter\Security\DraftTokenService;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * A10 (audit 2026-11-07): two requests submit the same prepared report at the
 * same time (a double click, a retry while the first request still runs). The
 * second request looked for the report before the first stored it, so both
 * store under the identifier reserved in the draft; the database lets one
 * win and the other answers with that report, without delivering it again.
 * The interleaving is forced: the lookup of the second request runs the
 * complete first request before it answers "not stored yet".
 */
final class ConcurrentSubmissionTest extends AbstractContextReporterTestCase
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
    public function theSameDraftSubmittedTwiceAtTheSameTimeIsStoredAndDeliveredOnce(): void
    {
        $editor = $this->loginBackendUser(self::EDITOR);
        $request = CollectionRequest::fromArray(['source' => 'contextMenu', 'target' => ['type' => 'page', 'uid' => 2]], new BackendLocationFactory());
        $prepared = $this->get(ReportService::class)->prepare($request, $editor, $GLOBALS['TYPO3_REQUEST']);
        $submission = new ReportSubmission($prepared->draftToken, 'Teaser is broken', '', null, true);

        $first = null;
        $firstService = $this->get(ReportService::class);
        $reports = new class ($this->get(ConnectionPool::class)) extends ReportRepository {
            /** @var \Closure|null runs once, inside the second request's lookup */
            public ?\Closure $meanwhile = null;

            public function findByIdentifier(string $identifier): ?Report
            {
                if ($this->meanwhile !== null) {
                    $before = parent::findByIdentifier($identifier);
                    ($this->meanwhile)();
                    $this->meanwhile = null;
                    return $before;
                }
                return parent::findByIdentifier($identifier);
            }
        };
        $reports->meanwhile = static function () use (&$first, $firstService, $submission, $editor): void {
            $first = $firstService->submit($submission, $editor);
        };
        $secondService = new ReportService(
            $this->get(ContextDocumentBuilder::class),
            $this->get(DraftTokenService::class),
            $this->get(ExtensionSettingsProvider::class),
            $this->get(ScreenshotValidator::class),
            $this->get(ReportIdGenerator::class),
            $reports,
            $this->get(DeliveryService::class),
        );

        $second = $secondService->submit($submission, $editor);

        self::assertNotNull($first);
        self::assertFalse($first->replayed);
        self::assertTrue($second->replayed, 'The second request answers with the stored report');
        self::assertSame($prepared->reportIdentifier, $first->report->identifier);
        self::assertSame($first->report->identifier, $second->report->identifier);
        self::assertSame(1, (int)$this->get(ConnectionPool::class)->getConnectionForTable(ReportRepository::TABLE)->count('*', ReportRepository::TABLE, []));
        self::assertCount(1, $this->webhookRequests, 'One initial webhook delivery');
        self::assertSame(1, substr_count((string)file_get_contents($this->mailbox), 'Subject: [Functional Test Portal] Teaser is broken'), 'One email');
        self::assertCount(2, $this->get(DeliveryAttemptRepository::class)->findByReportUid((int)$first->report->uid), 'One attempt per destination');
        self::assertSame(['email', 'webhook'], array_map(static fn($attempt): string => $attempt->destination, $second->attempts));
    }
}
