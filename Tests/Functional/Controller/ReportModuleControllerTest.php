<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Controller;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Controller\ReportModuleController;
use Priebera\ContextReporter\Domain\DeliveryAttempt;
use Priebera\ContextReporter\Domain\DeliveryState;
use Priebera\ContextReporter\Domain\DeliveryStatus;
use Priebera\ContextReporter\Domain\Repository\DeliveryAttemptRepository;
use Priebera\ContextReporter\Domain\Repository\ReportRepository;
use Priebera\ContextReporter\Domain\ReviewState;
use Priebera\ContextReporter\Hub\HubReceipt;
use Priebera\ContextReporter\Report\ReportService;
use Priebera\ContextReporter\Report\ReportSubmission;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use Priebera\ContextReporter\Tests\Unit\Fixtures\ReportFixture;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Information\Typo3Version;

final class ReportModuleControllerTest extends AbstractContextReporterTestCase
{
    use ModuleRequestTrait;

    private const SCREENSHOT = __DIR__ . '/../../Unit/Fixtures/Images/screenshot.png';
    private const HUB_URL = 'https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P';
    private const HUB_SECRET = 'whsec_8Fq2LmZx4RtY7vB1nKc3Wd9PaQ5sT0uV';

    /**
     * @var list<RequestInterface>
     */
    private array $hubRequests = [];

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['contextReporterTest']);
        parent::tearDown();
    }

    #[Test]
    public function listShowsTheObjectOfEachReportCompactly(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $this->createReport('Teaser is broken', ['source' => 'recordList', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 10]]);
        $this->createReport('Logo is outdated', ['source' => 'fileList', 'target' => ['type' => 'file', 'uid' => self::FILE_LOGO]], true);
        $this->createReport('Folder is empty', ['source' => 'fileList', 'target' => ['type' => 'folder', 'identifier' => '1:/private/']]);
        $this->createReport('Module is slow', ['source' => 'toolbar', 'location' => ['url' => '/typo3/module/site/configuration', 'module' => 'site_configuration']]);

        $html = $this->render('system_contextreports', []);

        self::assertSame(4, substr_count($html, 'data-context-reporter-row=""'));
        self::assertStringContainsString('@priebera/context-reporter/report-list.js', $html);
        foreach ([
            'Teaser is broken' => ['Hero teaser', 'tt_content:10', 'About'],
            'Logo is outdated' => ['logo.png', 'sys_file:1', 'fileadmin: /user_upload/'],
            'Folder is empty' => ['private', '1:/private/', 'fileadmin'],
            'Module is slow' => [self::coreModuleLabel('site_configuration'), 'site_configuration'],
        ] as $title => $expected) {
            $row = $this->extractRow($html, $title);
            foreach ($expected as $text) {
                self::assertStringContainsString(htmlspecialchars($text), $row, $title);
            }
        }
        self::assertStringContainsString('Has a screenshot', $this->extractRow($html, 'Logo is outdated'));
        self::assertStringNotContainsString('workspace Live', $html, 'The long summary is not part of the list');
    }

    #[Test]
    public function settingsAreOpenedFromTheRightSideOfTheDocumentHeader(): void
    {
        $this->loginBackendUser(self::ADMIN);

        // TYPO3 v14 renamed the document header markup (module-docheader-bar-* became module-docheader-*)
        $v14 = (new Typo3Version())->getMajorVersion() >= 14;
        $buttonBar = $v14 ? 'module-docheader module-docheader-buttons' : 'module-docheader-bar-buttons';
        $leftColumn = $v14 ? 'class="module-docheader-column module-docheader-column-grow"' : 'module-docheader-bar-column-left';
        $rightColumn = $v14 ? 'class="module-docheader-column">' : 'module-docheader-bar-column-right';

        $buttons = $this->extractBetween($this->render('system_contextreports', []), $buttonBar, 'module-body');

        self::assertStringNotContainsString('context-reports/settings', $this->extractBetween($buttons, $leftColumn, $rightColumn));
        $right = $this->extractBetween($buttons, $rightColumn, 'module-body');
        self::assertStringContainsString('context-reports/settings', $right);
        self::assertStringContainsString('Settings', $right);
    }

    #[Test]
    public function filteredListWithoutReportsExplainsTheFilter(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $this->createReport('Teaser is broken', ['source' => 'recordList', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 10]]);

        $filtered = $this->render('system_contextreports', ['state' => 'failed']);
        self::assertStringContainsString('No matching reports', $filtered);
        self::assertStringContainsString('callout callout-info', $filtered, 'The empty state keeps its severity on both TYPO3 branches');
        self::assertStringContainsString('No reports yet', $this->renderWithoutReports());
    }

    #[Test]
    public function detailShowsTheSummaryFirstAndTechnicalDetailsCollapsed(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $identifier = $this->createReport('Logo is outdated', ['source' => 'fileList', 'target' => ['type' => 'file', 'uid' => self::FILE_LOGO]], true);

        $html = $this->render('system_contextreports.show', ['report' => $identifier]);

        $summary = $this->extractBetween($html, 'id="cr-subject-heading"', 'cr-screenshot-card');
        self::assertStringContainsString('File · image/png', $summary);
        self::assertStringContainsString('logo.png', $summary);
        self::assertStringContainsString('sys_file:1', $summary);
        self::assertStringContainsString('Open containing folder in TYPO3', $summary, 'The file list opens at the folder of the file');
        self::assertStringContainsString('Edit metadata', $summary);
        self::assertStringContainsString('module/file/list', $summary);
        self::assertStringNotContainsString('Open frontend', $summary);
        self::assertStringNotContainsString('https://backend.example.com', strip_tags($summary), 'Links are actions, not text');
        self::assertStringContainsString('<img', $this->extractBetween($html, 'cr-screenshot-card', 'cr-description-heading'));

        $technical = $this->extractBetween($html, 'cr-technical-details', 'cr-danger-zone');
        self::assertStringContainsString('<details>', $technical, 'Technical details are collapsed');
        self::assertStringNotContainsString('<details open', $technical);
        self::assertStringContainsString('Storage UID', $technical);
        self::assertStringContainsString('https://backend.example.com/typo3/module/file/list', $technical);
        $dangerZone = $this->extractBetween($html, 'cr-danger-zone', '</html>');
        self::assertStringContainsString('Delete report', $dangerZone);
        // Without the confirmation script, a click must not submit the form
        self::assertMatchesRegularExpression('/<form id="cr-delete-form" action="[^"]+" method="post">/', $dangerZone);
        self::assertMatchesRegularExpression('/<button\s+type="button"\s+class="btn btn-danger t3js-modal-trigger"\s+data-target-form="cr-delete-form"/', $dangerZone);
        // TYPO3 v14 only reads data-content; data-bs-content confirms with a generic question
        self::assertStringContainsString('data-content="Report ' . $identifier . ' will be removed permanently."', $dangerZone);
        self::assertStringNotContainsString('data-bs-content', $dangerZone);
    }

    #[Test]
    public function detailWorksForReportsWithoutScreenshotOrDelivery(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $identifier = $this->createReport('Module is slow', ['source' => 'toolbar', 'location' => ['url' => '/typo3/module/site/configuration', 'module' => 'site_configuration']]);

        $html = $this->render('system_contextreports.show', ['report' => $identifier]);

        self::assertStringContainsString('Backend module', $html);
        self::assertStringContainsString('No screenshot was attached.', $html);
        self::assertStringNotContainsString('cr-screenshot-card', $html);
        self::assertStringContainsString('The report has not been delivered anywhere.', $html);
        self::assertStringContainsString('via Backend toolbar', $html);
        self::assertStringContainsString('JSON (.json)', $html);
        self::assertStringNotContainsString('JSON with screenshot', $html);
        self::assertStringNotContainsString('format=screenshot', $html);
    }

    #[Test]
    public function detailShowsVisibilityNoticesBelowTheReportedObject(): void
    {
        $this->loginBackendUser(self::ADMIN);
        // The German translation [12] of the element is hidden
        $identifier = $this->createReport('Teaser missing in German', ['source' => 'contextMenu', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 10]]);

        $html = $this->render('system_contextreports.show', ['report' => $identifier]);

        $subject = $this->extractBetween($html, 'id="cr-subject-heading"', 'cr-description-heading');
        self::assertStringContainsString('id="cr-findings-heading">Findings</h3>', $subject);
        self::assertStringContainsString('id="cr-findings-visibility-heading"', $subject);
        self::assertStringContainsString('<li>Content element translation Deutsch: Hidden</li>', $subject);
        self::assertStringContainsString('These are settings and permissions stored in TYPO3, not a check of the website', $subject);
        $technical = $this->extractBetween($html, 'cr-technical-details', 'cr-danger-zone');
        self::assertStringContainsString('Translation Deutsch [1]', $technical);
    }

    #[Test]
    public function detailOfReportsWithoutVisibilityDataHasNoNotices(): void
    {
        $this->loginBackendUser(self::ADMIN);
        // Stored before the visibility settings were collected
        $report = $this->get(ReportRepository::class)->add(ReportFixture::report(withScreenshot: false));

        $html = $this->render('system_contextreports.show', ['report' => $report->identifier]);

        self::assertStringContainsString('Hero teaser', $html);
        self::assertStringNotContainsString('cr-findings', $html);
    }

    #[Test]
    public function detailShowsFileChecksBelowTheReportedObject(): void
    {
        $this->loginBackendUser(self::ADMIN);
        // manual.pdf is marked as missing and not in the storage
        $identifier = $this->createReport('Manual cannot be downloaded', ['source' => 'contextMenu', 'target' => ['type' => 'file', 'uid' => self::FILE_MISSING]]);

        $html = $this->render('system_contextreports.show', ['report' => $identifier]);

        $subject = $this->extractBetween($html, 'id="cr-subject-heading"', 'cr-description-heading');
        self::assertStringContainsString('id="cr-findings-files-heading"', $subject);
        self::assertStringContainsString('<li>Not found in its storage</li>', $subject);
        self::assertStringNotContainsString('id="cr-findings-visibility-heading"', $subject);
        $technical = $this->extractBetween($html, 'cr-technical-details', 'cr-danger-zone');
        self::assertStringContainsString('not found in its storage; marked as missing', $technical);
    }

    #[Test]
    public function detailOfReportsWithoutFileChecksHasNoFileNotices(): void
    {
        $this->loginBackendUser(self::ADMIN);
        // Stored before the file checks were collected
        $report = $this->get(ReportRepository::class)->add(ReportFixture::report(withScreenshot: false));

        $html = $this->render('system_contextreports.show', ['report' => $report->identifier]);

        self::assertStringContainsString('Hero teaser', $html);
        self::assertStringNotContainsString('cr-findings-files', $html);
    }

    #[Test]
    public function reportsCanBeResolvedAndReopened(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $identifier = $this->createReport('Teaser is broken', ['source' => 'recordList', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 10]]);
        self::assertStringContainsString('Mark as resolved', $this->render('system_contextreports.show', ['report' => $identifier]));

        $response = $this->post('resolve', ['report' => $identifier]);

        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('report=' . $identifier, $response->getHeaderLine('Location'));
        self::assertSame('Report ' . $identifier . ' was marked as resolved.', $this->getFlashMessages()[0]['message']);
        $report = $this->get(ReportRepository::class)->findByIdentifier($identifier);
        self::assertSame(ReviewState::Resolved, $report?->reviewState);
        self::assertSame(self::ADMIN, $report->resolvedBy);
        self::assertSame(DeliveryState::Local, $report->deliveryState);

        $open = $this->render('system_contextreports', []);
        self::assertStringContainsString('No open reports', $open);
        self::assertStringNotContainsString('data-context-reporter-row=""', $open);
        $resolved = $this->render('system_contextreports', ['review' => 'resolved']);
        self::assertStringContainsString('Resolved', $this->extractRow($resolved, 'Teaser is broken'));
        self::assertSame(1, substr_count($this->render('system_contextreports', ['review' => 'all']), 'data-context-reporter-row=""'));

        $detail = $this->render('system_contextreports.show', ['report' => $identifier]);
        self::assertStringContainsString('by Alice Admin (admin)', $detail);
        self::assertStringContainsString('Reopen', $detail);

        $this->post('resolve', ['report' => $identifier]);
        self::assertSame('INFO', $this->getFlashMessages()[0]['severity'], 'Resolving twice changes nothing');

        $this->post('reopen', ['report' => $identifier]);
        self::assertSame('Report ' . $identifier . ' was reopened.', $this->getFlashMessages()[0]['message']);
        $reopened = $this->get(ReportRepository::class)->findByIdentifier($identifier);
        self::assertSame(ReviewState::Open, $reopened?->reviewState);
        self::assertNull($reopened->resolvedAt);
    }

    #[Test]
    public function onlyAdministratorsCanViewOrChangeReports(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $identifier = $this->createReport('Teaser is broken', ['source' => 'recordList', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 10]]);
        $this->loginBackendUser(self::EDITOR);
        $controller = $this->get(ReportModuleController::class);

        self::assertSame(403, $controller->indexAction($this->createModuleRequest('system_contextreports'))->getStatusCode());
        self::assertSame(403, $controller->showAction($this->createModuleRequest('system_contextreports.show', ['report' => $identifier]))->getStatusCode());
        foreach (['resolve', 'reopen', 'delete', 'retry'] as $action) {
            $response = $controller->{$action . 'Action'}($this->createModuleRequest('system_contextreports.' . $action, body: ['report' => $identifier, 'destination' => 'email']));
            self::assertSame(403, $response->getStatusCode(), $action);
        }
        $report = $this->get(ReportRepository::class)->findByIdentifier($identifier);
        self::assertSame(ReviewState::Open, $report?->reviewState);
    }

    #[Test]
    public function deletingAReportRemovesItsScreenshot(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $identifier = $this->createReport('Logo is outdated', ['source' => 'fileList', 'target' => ['type' => 'file', 'uid' => self::FILE_LOGO]], true);
        $uid = $this->get(ReportRepository::class)->findByIdentifier($identifier)->uid ?? 0;

        $response = $this->post('delete', ['report' => $identifier]);

        self::assertSame(303, $response->getStatusCode());
        self::assertNull($this->get(ReportRepository::class)->findByIdentifier($identifier));
        self::assertNull($this->get(ReportRepository::class)->findScreenshotContent($uid));
        self::assertSame(['attachments' => 0, 'deliveries' => 0], $this->get(ReportRepository::class)->countOrphans());
    }

    #[Test]
    public function storedDeliveryDetailsAreSanitizedWhenDisplayed(): void
    {
        $this->configureExtension(['webhook' => [
            'enabled' => '1',
            'url' => 'https://hooks.example.com/webhook/5f1c-secret-path',
            'authHeaderValue' => 'Bearer bearer-token-value',
        ]]);
        $this->loginBackendUser(self::ADMIN);
        $identifier = $this->createReport('Teaser is broken', ['source' => 'recordList', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 10]]);
        $reportUid = $this->get(ReportRepository::class)->findByIdentifier($identifier)->uid ?? 0;
        // Stored by a version that did not sanitize successful responses yet
        $attempts = $this->get(DeliveryAttemptRepository::class);
        $attempts->add(new DeliveryAttempt($reportUid, 'webhook', 1, DeliveryStatus::Succeeded, new \DateTimeImmutable('-1 hour'), 'https://hooks.example.com', 1, 201, 'HTTP 201', 'Bearer bearer-token-value', 'https://hooks.example.com/webhook/5f1c-secret-path'));
        $attempts->add(new DeliveryAttempt($reportUid, 'webhook', 2, DeliveryStatus::Failed, new \DateTimeImmutable(), 'https://hooks.example.com', 1, 401, 'HTTP 401: bearer-token-value is not valid'));

        $html = $this->render('system_contextreports.show', ['report' => $identifier]);

        self::assertStringContainsString('HTTP 401: [redacted] is not valid', $html);
        self::assertStringNotContainsString('bearer-token-value', $html);
        self::assertStringNotContainsString('5f1c-secret-path', $html);
    }

    #[Test]
    public function detailOffersCopyAndDownloadActions(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $identifier = $this->createReport('Logo is outdated', ['source' => 'fileList', 'target' => ['type' => 'file', 'uid' => self::FILE_LOGO]], true);

        $html = $this->render('system_contextreports.show', ['report' => $identifier]);

        self::assertStringContainsString('@typo3/backend/copy-to-clipboard.js', $html);
        preg_match_all('/<typo3-copy-to-clipboard\b[^>]*\btext="([^"]*)"[^>]*>/', $html, $matches);
        self::assertCount(4, $matches[1]);
        [$summary, $markdown, $json, $link] = array_map(static fn(string $value): string => html_entity_decode($value, ENT_QUOTES | ENT_HTML5), $matches[1]);
        self::assertStringStartsWith("Logo is outdated\nReport: " . $identifier, $summary);
        self::assertStringStartsWith("# Logo is outdated\n", $markdown);
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('context-reporter.report.v1', $payload['schema']);
        self::assertSame($identifier, $payload['id']);
        self::assertArrayNotHasKey('contentBase64', $payload['attachments'][0]);
        self::assertStringNotContainsString('contentBase64', $html);
        self::assertStringEndsWith('/typo3/module/system/context-reports/show?report=' . $identifier, $link);
        self::assertStringNotContainsString('token=', $link);

        foreach (['format=markdown', 'format=json', 'format=screenshot&amp;download=1'] as $format) {
            self::assertStringContainsString($format, $html);
        }
        self::assertStringContainsString('JSON with screenshot (.json)', $html);
    }

    #[Test]
    public function longRecordTitlesDoNotDominateTheList(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $this->createReport('Long header', ['source' => 'recordList', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 14]]);

        $row = $this->extractRow($this->render('system_contextreports', []), 'Long header');

        self::assertStringContainsString('>Plain HTML<span class="visually-hidden">: Quarterly accessibility review', $row);
        self::assertStringContainsString('tt_content:14', $row);
        self::assertStringNotContainsString('Hidden body', $row);
    }

    #[Test]
    public function hubCardInvitesToConnectTheHubWhenItIsNotConfigured(): void
    {
        $this->respondToHub(new Response(200));
        $this->loginBackendUser(self::ADMIN);

        $html = $this->render('system_contextreports', []);

        $cta = $this->extractBetween($html, 'class="cr-hub-cta"', '</aside>');
        self::assertStringContainsString('Context Reporter Hub', $cta);
        self::assertStringContainsString('Reports, screenshots, email and the webhook work without it.', $cta);
        self::assertStringContainsString('Connect Hub', $cta);
        self::assertStringContainsString('context-reports/settings?', $cta);
        self::assertStringContainsString('tab=hub', $cta);
        self::assertStringNotContainsString('Open Context Reporter Hub', $html);
        self::assertSame([], $this->hubRequests, 'The history never asks the Hub itself');
    }

    #[Test]
    public function hubCardShowsTheLocalConnectionFactsWithoutAskingTheHub(): void
    {
        $this->respondToHub(new Response(200));
        $this->configureExtension(['hub' => ['enabled' => '1', 'url' => self::HUB_URL, 'secret' => self::HUB_SECRET]]);
        $this->loginBackendUser(self::ADMIN);
        $identifier = $this->createReport('Teaser is broken', ['source' => 'recordList', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 10]]);
        $reportUid = $this->get(ReportRepository::class)->findByIdentifier($identifier)->uid ?? 0;
        $attempts = $this->get(DeliveryAttemptRepository::class);
        $attempts->add(new DeliveryAttempt($reportUid, 'hub', 1, DeliveryStatus::Failed, new \DateTimeImmutable('-2 hours'), 'https://hub.example.com', 1, 401, 'HTTP 401: signature of ' . self::HUB_SECRET . ' does not match'));
        $attempts->add(new DeliveryAttempt($reportUid, 'hub', 2, DeliveryStatus::Succeeded, new \DateTimeImmutable('-1 hour'), 'https://hub.example.com', 1, 200, 'HTTP 200', 'LW-184', 'https://hub.example.com/app/reports/LW-184'));

        $html = $this->render('system_contextreports', []);

        $card = $this->extractBetween($html, 'class="card cr-card cr-hub"', '</section>');
        self::assertStringContainsString('Context Reporter Hub', $card);
        self::assertStringContainsString('https://hub.example.com', $card);
        self::assertStringContainsString('Delivery on', $card);
        self::assertStringContainsString('Last delivery to the Hub', $card);
        self::assertStringContainsString($identifier, $card);
        self::assertStringContainsString('<strong>LW-184</strong>', $card);
        self::assertStringContainsString('Last failed delivery', $card);
        self::assertStringContainsString('(later deliveries succeeded)', $card);
        self::assertStringContainsString('[redacted]', $card);
        // The Hub is write-only for TYPO3: no overview is read, the card opens the Hub app instead
        self::assertStringNotContainsString('overview', $card);
        self::assertStringNotContainsString('hub-card.js', $html);
        self::assertStringContainsString('href="https://hub.example.com/app" target="_blank" rel="noopener noreferrer"', $card);
        self::assertStringContainsString('Open Context Reporter Hub', $card);
        self::assertStringContainsString('reads nothing back', $card);
        self::assertMatchesRegularExpression('/<form method="post" action="[^"]*context-reports\/testHub\?token=[^"]+" class="cr-inline-form">\s*<input type="hidden" name="returnTo" value="index" \/>/', $card);
        self::assertStringContainsString('Hub settings', $card);
        foreach (['whsec_', 's_8Fq2LmZx4RtY7vB1nKc3Wd9P'] as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }
        self::assertSame([], $this->hubRequests, 'The card never asks the Hub');
    }

    #[Test]
    public function deliveryHistoryAndHubCardNameAScreenshotTheHubDidNotStore(): void
    {
        $this->respondToHub(new Response(200));
        $this->configureExtension(['hub' => ['enabled' => '1', 'url' => self::HUB_URL, 'secret' => self::HUB_SECRET]]);
        $this->loginBackendUser(self::ADMIN);
        $identifier = $this->createReport('Teaser is broken', ['source' => 'recordList', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 10]]);
        $reportUid = $this->get(ReportRepository::class)->findByIdentifier($identifier)->uid ?? 0;
        $receipt = HubReceipt::fromAnswer([
            'screenshots' => ['received' => 1, 'stored' => 0, 'omitted' => 1, 'reasons' => ['storage_full']],
            'storage' => 'full',
        ]);
        $this->get(DeliveryAttemptRepository::class)->add(new DeliveryAttempt(
            $reportUid,
            'hub',
            1,
            DeliveryStatus::Succeeded,
            new \DateTimeImmutable('-1 hour'),
            'https://hub.example.com',
            1,
            200,
            'HTTP 200',
            'LW-184',
            'https://hub.example.com/app/reports/LW-184',
            hubReceipt: $receipt,
        ));

        $detail = $this->extractBetween($this->render('system_contextreports.show', ['report' => $identifier]), 'id="cr-deliveries-heading"', '</section>');
        self::assertStringContainsString('badge-success', $detail);
        self::assertStringContainsString('LW-184', $detail);
        self::assertStringContainsString('The Hub received the report but did not store its screenshot.', $detail);
        self::assertStringContainsString('The storage of the Hub workspace is full.', $detail);
        self::assertStringContainsString('At this delivery, the storage of the Hub workspace was full.', $detail);

        $card = $this->extractBetween($this->render('system_contextreports', []), 'class="card cr-card cr-hub"', '</section>');
        self::assertStringContainsString('<strong>LW-184</strong>', $card);
        self::assertStringContainsString('At this delivery, the storage of the Hub workspace was full.', $card);
    }

    #[Test]
    public function hubCardOpensTheHubOnTheHubAddressForLinksAndEditorsSeeNoSettings(): void
    {
        $this->respondToHub(new Response(200));
        $this->configureExtension(['hub' => ['enabled' => '1', 'url' => self::HUB_URL, 'secret' => self::HUB_SECRET, 'publicUrl' => 'https://inbox.example.com/hub']]);
        $this->loginBackendUser(self::REVIEWER);

        $card = $this->extractBetween($this->render('system_contextreports', []), 'class="card cr-card cr-hub"', '</section>');

        self::assertStringContainsString('href="https://inbox.example.com/hub/app"', $card);
        self::assertStringNotContainsString('Hub settings', $card);
        self::assertStringNotContainsString('testHub', $card);
        self::assertStringNotContainsString('https://hub.example.com', $card, 'Editors see no endpoint');

        // Without a "Hub address for links" the address of the webhook URL is not shown to editors
        $this->configureExtension(['hub' => ['enabled' => '1', 'url' => self::HUB_URL, 'secret' => self::HUB_SECRET, 'publicUrl' => '']]);
        $card = $this->extractBetween($this->render('system_contextreports', []), 'class="card cr-card cr-hub"', '</section>');
        self::assertStringNotContainsString('Open Context Reporter Hub', $card);
        self::assertStringNotContainsString('hub.example.com', $card);
        self::assertSame([], $this->hubRequests);
    }

    private function respondToHub(Response $response): void
    {
        $requests = &$this->hubRequests;
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['contextReporterTest'] = static function (callable $next) use (&$requests, $response): \Closure {
            return static function (RequestInterface $request, array $options) use (&$requests, $response) {
                $requests[] = $request;
                return Create::promiseFor($response);
            };
        };
    }

    /**
     * @param array<string, string> $body
     */
    private function post(string $action, array $body): ResponseInterface
    {
        $controller = $this->get(ReportModuleController::class);
        return $controller->{$action . 'Action'}($this->createModuleRequest('system_contextreports.' . $action, body: $body));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function createReport(string $title, array $input, bool $withScreenshot = false): string
    {
        $service = $this->get(ReportService::class);
        $prepared = $service->prepare(CollectionRequest::fromArray($input, new BackendLocationFactory()), $GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST']);
        $screenshot = $withScreenshot ? (string)file_get_contents(self::SCREENSHOT) : null;
        return $service->submit(new ReportSubmission($prepared->draftToken, $title, '', $screenshot, false), $GLOBALS['BE_USER'])->report->identifier;
    }

    /**
     * @param array<string, string> $queryParameters
     */
    private function render(string $routeIdentifier, array $queryParameters): string
    {
        $request = $this->createModuleRequest($routeIdentifier, $queryParameters);
        $controller = $this->get(ReportModuleController::class);
        $response = $routeIdentifier === 'system_contextreports.show' ? $controller->showAction($request) : $controller->indexAction($request);
        self::assertSame(200, $response->getStatusCode());
        return (string)$response->getBody();
    }

    private function renderWithoutReports(): string
    {
        $this->getConnectionPool()->getConnectionForTable('tx_contextreporter_report')->truncate('tx_contextreporter_report');
        return $this->render('system_contextreports', []);
    }

    private function extractRow(string $html, string $title): string
    {
        foreach (array_slice(explode('<tr', $html), 1) as $row) {
            if (str_contains($row, htmlspecialchars($title))) {
                $end = strpos($row, '</tr>');
                return '<tr' . ($end === false ? $row : substr($row, 0, $end));
            }
        }
        self::fail('No table row contains "' . $title . '".');
    }

    private function extractBetween(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        self::assertNotFalse($start, $from);
        $end = strpos($html, $to, $start + strlen($from));
        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }
}
