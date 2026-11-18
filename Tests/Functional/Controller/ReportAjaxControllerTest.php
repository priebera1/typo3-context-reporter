<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Controller;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Controller\ReportAjaxController;
use Priebera\ContextReporter\Controller\ReportDownloadController;
use Priebera\ContextReporter\Database\DatabaseSchemaState;
use Priebera\ContextReporter\Delivery\DeliveryNotPossibleException;
use Priebera\ContextReporter\Delivery\DeliveryService;
use Priebera\ContextReporter\Domain\Repository\DeliveryAttemptRepository;
use Priebera\ContextReporter\Domain\Repository\ReportRepository;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\UploadedFile;

final class ReportAjaxControllerTest extends AbstractContextReporterTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['contextReporterTest']);
        putenv('CR_AJAX_TEST_SECRET');
        parent::tearDown();
    }

    private const SCREENSHOT = __DIR__ . '/../../Unit/Fixtures/Images/screenshot.png';

    #[Test]
    public function prepareReturnsTheSealedContextAndEnabledDestinations(): void
    {
        $this->configureExtension(['webhook' => ['enabled' => '1', 'url' => 'https://hooks.example.com/secret-path']]);
        $this->loginBackendUser(self::ADMIN);

        $response = $this->prepare(['source' => 'contextMenu', 'target' => ['type' => 'page', 'uid' => 2]]);
        $data = $this->decode($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsString($data['draftToken']);
        self::assertSame('contextMenu', $data['source']);
        self::assertSame('page', $data['document']['subject']['type']);
        self::assertSame('Page', $data['presentation']['typeLabel']);
        self::assertSame('About', $data['presentation']['title']);
        self::assertSame('UID 2', $data['presentation']['identifier']);
        self::assertSame('main · English · Live', $data['presentation']['meta']);
        self::assertSame([['identifier' => 'webhook', 'label' => 'Webhook', 'target' => 'https://hooks.example.com']], $data['destinations']);
        self::assertSame(['titleMaxLength' => 150, 'descriptionMaxLength' => 5000, 'screenshotMaxBytes' => 5242880], $data['limits']);
    }

    #[Test]
    public function editorsSeeDestinationsWithoutTargets(): void
    {
        $this->configureExtension(['webhook' => ['enabled' => '1', 'url' => 'https://hooks.example.com/secret-path']]);
        $this->loginBackendUser(self::EDITOR);

        $data = $this->decode($this->prepare(['source' => 'toolbar']));

        self::assertSame([['identifier' => 'webhook', 'label' => 'Webhook']], $data['destinations']);
    }

    #[Test]
    public function prepareIsDeniedWhenReportingIsDisabled(): void
    {
        $this->loginBackendUser(self::BLOCKED_EDITOR);
        $response = $this->prepare(['source' => 'toolbar']);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('accessDenied', $this->decode($response)['error']['code']);

        $this->configureExtension(['general' => ['enabled' => '0']]);
        $this->loginBackendUser(self::ADMIN);
        self::assertSame(403, $this->prepare(['source' => 'toolbar'])->getStatusCode());
    }

    #[Test]
    public function prepareRejectsInvalidAndInaccessibleTargets(): void
    {
        $this->loginBackendUser(self::EDITOR);

        $notAvailable = $this->prepare(['source' => 'contextMenu', 'target' => ['type' => 'page', 'uid' => 4]]);
        self::assertSame(404, $notAvailable->getStatusCode());
        self::assertSame('Die gewählte Seite, der Datensatz, die Datei oder der Ordner ist für Sie nicht verfügbar.', $this->decode($notAvailable)['error']['message']);
        self::assertSame(404, $this->prepare(['source' => 'fileList', 'target' => ['type' => 'file', 'uid' => self::FILE_BUDGET]])->getStatusCode());
        self::assertSame(404, $this->prepare(['source' => 'fileList', 'target' => ['type' => 'folder', 'identifier' => '1:/private/']])->getStatusCode());
        self::assertSame(400, $this->prepare(['source' => 'fileList', 'target' => ['type' => 'folder', 'identifier' => '0:/fileadmin/']])->getStatusCode());

        self::assertSame(400, $this->prepare(['source' => 'contextMenu', 'target' => ['type' => 'record', 'table' => 'tt_content;', 'uid' => 10]])->getStatusCode());
        self::assertSame(400, $this->callPrepare('{not json')->getStatusCode());
        self::assertSame(400, $this->callPrepare(str_repeat(' ', 70000) . '{}')->getStatusCode());
    }

    #[Test]
    public function fileReportsCanBePreparedAndSubmitted(): void
    {
        $this->loginBackendUser(self::EDITOR);

        $prepared = $this->decode($this->prepare(['source' => 'fileList', 'target' => ['type' => 'file', 'uid' => self::FILE_LOGO]]));
        self::assertSame('fileList', $prepared['source']);
        self::assertSame('Datei · image/png', $prepared['presentation']['typeLabel']);
        self::assertSame('logo.png', $prepared['presentation']['title']);
        self::assertSame('sys_file:1', $prepared['presentation']['identifier']);

        $data = $this->decode($this->submit(['draftToken' => $prepared['draftToken'], 'title' => 'Logo is outdated', 'action' => 'send']));
        $report = $this->get(ReportRepository::class)->findByIdentifier($data['report']['identifier']);
        self::assertNotNull($report);
        self::assertSame('file', $report->document->getSubjectType()->value);
        self::assertSame('fileList', $report->source->value);
        $row = $this->getConnectionPool()->getConnectionForTable('tx_contextreporter_report')
            ->select(['subject_type', 'subject_table', 'subject_uid', 'subject_label'], 'tx_contextreporter_report', ['uid' => $report->uid])
            ->fetchAssociative();
        self::assertSame(['subject_type' => 'file', 'subject_table' => 'sys_file', 'subject_uid' => 1, 'subject_label' => 'logo.png'], array_map(
            static fn(mixed $value): mixed => is_numeric($value) ? (int)$value : $value,
            $row ?: [],
        ));
    }

    #[Test]
    public function submitStoresTheReportWithScreenshotAndOffersDownloads(): void
    {
        $this->loginBackendUser(self::EDITOR);
        $token = $this->decode($this->prepare(['source' => 'contextMenu', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 10]]))['draftToken'];

        $response = $this->submit(['draftToken' => $token, 'title' => 'Broken teaser', 'description' => 'Details', 'action' => 'send'], true);
        $data = $this->decode($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Broken teaser', $data['report']['title']);
        self::assertSame('local', $data['deliveryState']);
        self::assertSame([], $data['deliveries']);
        self::assertSame('', $data['historyUrl']);
        self::assertTrue($data['report']['hasScreenshot']);
        self::assertSame(['markdown', 'json', 'screenshot'], array_keys($data['downloads']));
        self::assertStringContainsString('/typo3/context-reporter/download?token=', $data['downloads']['json']);
        self::assertStringContainsString('format=markdown', $data['downloads']['markdown']);
        self::assertStringContainsString('format=screenshot', $data['downloads']['screenshot']);
        self::assertStringContainsString('download=1', $data['downloads']['screenshot']);

        $report = $this->get(ReportRepository::class)->findByIdentifier($data['report']['identifier']);
        self::assertNotNull($report?->screenshot);
        self::assertSame('image/png', $report->screenshot->mediaType);

        $identifier = $report->identifier;
        $copy = $data['copy'];
        self::assertStringStartsWith("Broken teaser\nReport: " . $identifier, $copy['summary']);
        self::assertStringStartsWith('# Broken teaser', $copy['markdown']);
        self::assertStringContainsString('## Description', $copy['markdown']);
        $json = json_decode($copy['json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($identifier, $json['id']);
        self::assertSame('Details', $json['description']);
        self::assertSame('image/png', $json['attachments'][0]['mediaType']);
        self::assertArrayNotHasKey('contentBase64', $json['attachments'][0]);
        self::assertSame('https://backend.example.com/typo3/module/system/context-reports/show?report=' . $identifier, $copy['link']);
        self::assertSame($copy['link'], $json['links']['report']);
    }

    #[Test]
    public function usersWithTheReportsModuleCanOpenTheSubmittedReport(): void
    {
        $this->loginBackendUser(self::REVIEWER);

        $data = $this->submitReport('Reviewer report');

        self::assertStringContainsString('/typo3/module/system/context-reports/show?', $data['historyUrl']);
        self::assertStringContainsString('report=' . $data['report']['identifier'], $data['historyUrl']);
    }

    #[Test]
    public function administratorsCanOpenTheSubmittedReport(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $data = $this->submitReport('Admin report');

        self::assertStringContainsString('/typo3/module/system/context-reports/show?', $data['historyUrl']);
        self::assertStringContainsString('report=' . $data['report']['identifier'], $data['historyUrl']);
        self::assertStringContainsString('token=', $data['historyUrl']);
        self::assertStringNotContainsString('token=', $data['copy']['link']);
        self::assertFalse($data['report']['hasScreenshot']);
        self::assertSame(['markdown', 'json'], array_keys($data['downloads']));
    }

    #[Test]
    public function echoedWebhookSecretsAreNeitherStoredNorReturned(): void
    {
        putenv('CR_AJAX_TEST_SECRET=env-hmac-secret-value');
        $this->configureExtension(['webhook' => [
            'enabled' => '1',
            'url' => 'https://hooks.example.com/webhook/5f1c-secret-path?key=query-token-value',
            'secret' => '%env(CR_AJAX_TEST_SECRET)%',
            'authHeaderName' => 'Authorization',
            'authHeaderValue' => 'Bearer bearer-token-value',
        ]]);
        $this->respondWith([
            'reference' => 'Bearer bearer-token-value',
            'url' => 'https://hooks.example.com/webhook/5f1c-secret-path?key=query-token-value',
            'data' => ['id' => 'env-hmac-secret-value'],
        ]);
        $this->loginBackendUser(self::EDITOR);

        $echoed = $this->submitReport('Echoing receiver');
        $this->respondWith(['ticket' => ['key' => 'SUP-42', 'url' => 'https://desk.example.com/tickets/42?access_token=query-token-value#history']]);
        $ticket = $this->submitReport('Ticket receiver');

        self::assertSame('delivered', $echoed['deliveryState']);
        self::assertSame(['', ''], [$echoed['deliveries'][0]['externalReference'], $echoed['deliveries'][0]['externalUrl']]);
        self::assertSame('SUP-42', $ticket['deliveries'][0]['externalReference']);
        self::assertSame('https://desk.example.com/tickets/42#history', $ticket['deliveries'][0]['externalUrl']);
        $stored = json_encode(
            $this->getConnectionPool()->getConnectionForTable('tx_contextreporter_delivery')->select(['*'], 'tx_contextreporter_delivery')->fetchAllAssociative(),
            JSON_THROW_ON_ERROR,
        );
        foreach ([json_encode([$echoed, $ticket], JSON_THROW_ON_ERROR), $stored] as $output) {
            foreach (['bearer-token-value', 'env-hmac-secret-value', 'query-token-value', '5f1c-secret-path'] as $secret) {
                self::assertStringNotContainsString($secret, $output);
            }
        }
        self::assertStringContainsString('SUP-42', $stored);
    }

    /**
     * The Hub stores a report before it answers: when the answer is lost, the reporter is not told that the report was not
     * sent. The delivery stays failed (and can be retried), but is marked unconfirmed; a refused connection is not.
     */
    #[Test]
    public function aHubDeliveryWhoseAnswerWasLostIsUnconfirmed(): void
    {
        $this->configureExtension(['hub' => ['enabled' => '1', 'url' => 'https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P', 'secret' => 'whsec_8Fq2LmZx4RtY7vB1nKc3Wd9PaQ5sT0uV']]);
        $this->failWith('cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received');
        $this->loginBackendUser(self::ADMIN);
        $lost = $this->submitReport('Answer lost');
        $this->failWith('cURL error 7: Failed to connect to hub.example.com port 443: Connection refused');
        $refused = $this->submitReport('Hub not reachable');

        self::assertSame('failed', $lost['deliveryState']);
        self::assertFalse($lost['deliveries'][0]['successful']);
        self::assertTrue($lost['deliveries'][0]['unconfirmed']);
        self::assertFalse($refused['deliveries'][0]['successful']);
        self::assertFalse($refused['deliveries'][0]['unconfirmed']);
        $messages = $this->getConnectionPool()->getConnectionForTable('tx_contextreporter_delivery')
            ->select(['message'], 'tx_contextreporter_delivery', ['destination' => 'hub'], [], ['uid' => 'ASC'])->fetchFirstColumn();
        self::assertStringStartsWith('Not confirmed: No answer within the time limit (timeout)', (string)$messages[0]);
        self::assertSame('The server could not be reached (connection refused or blocked) (cURL error 7).', $messages[1]);
    }

    #[Test]
    public function theReporterSeesTheHubReferenceWithItsLink(): void
    {
        $this->configureExtension(['hub' => ['enabled' => '1', 'url' => 'https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P', 'secret' => 'whsec_8Fq2LmZx4RtY7vB1nKc3Wd9PaQ5sT0uV']]);
        $this->respondWith(['reference' => 'LW-152', 'url' => 'https://hub.example.com/app/reports/LW-152']);
        $this->loginBackendUser(self::ADMIN);

        $prepared = $this->decode($this->prepare(['source' => 'toolbar']));
        $result = $this->submitReport('Sent to the Hub');

        self::assertSame([['identifier' => 'hub', 'label' => 'Context Reporter Hub', 'target' => 'https://hub.example.com']], $prepared['destinations']);
        self::assertSame('delivered', $result['deliveryState']);
        self::assertSame([[
            'destination' => 'hub',
            'label' => 'Context Reporter Hub',
            'successful' => true,
            'unconfirmed' => false,
            'externalReference' => 'LW-152',
            'externalUrl' => 'https://hub.example.com/app/reports/LW-152',
            'screenshotNotStored' => null,
        ]], $result['deliveries']);
        foreach ([json_encode([$prepared['destinations'], $result], JSON_THROW_ON_ERROR)] as $output) {
            self::assertStringNotContainsString('s_8Fq2LmZx4RtY7vB1nKc3Wd9P', $output);
            self::assertStringNotContainsString('whsec_', $output);
        }
    }

    /**
     * The Hub received the report but not its screenshot (storage of the Hub
     * workspace full): received with its reference, plus a notice for the reporter.
     */
    #[Test]
    public function theReporterLearnsThatTheHubDidNotStoreTheScreenshot(): void
    {
        $this->configureExtension(['hub' => ['enabled' => '1', 'url' => 'https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P', 'secret' => 'whsec_8Fq2LmZx4RtY7vB1nKc3Wd9PaQ5sT0uV']]);
        $this->respondWith([
            'reference' => 'LW-152',
            'url' => 'https://hub.example.com/app/reports/LW-152',
            'screenshots' => ['received' => 1, 'stored' => 0, 'omitted' => 1, 'reasons' => ['storage_full']],
            'storage' => 'full',
        ]);
        $this->loginBackendUser(self::ADMIN);

        $result = $this->submitReport('Hub storage full');

        self::assertSame('delivered', $result['deliveryState']);
        self::assertTrue($result['deliveries'][0]['successful']);
        self::assertSame('LW-152', $result['deliveries'][0]['externalReference']);
        self::assertSame(['count' => 1, 'reason' => 'storage_full'], $result['deliveries'][0]['screenshotNotStored']);

        $row = $this->getConnectionPool()->getConnectionForTable('tx_contextreporter_delivery')
            ->select(['status', 'external_reference', 'hub_receipt'], 'tx_contextreporter_delivery', ['destination' => 'hub'])
            ->fetchAssociative();
        self::assertSame('succeeded', $row['status'] ?? null);
        self::assertSame('LW-152', $row['external_reference'] ?? null);
        self::assertSame('{"screenshots":{"received":1,"stored":0,"omitted":1,"reasons":["storage_full"]},"storage":"full"}', $row['hub_receipt'] ?? null);
    }

    #[Test]
    public function submitReportsValidationProblemsWithStatusCodes(): void
    {
        $this->loginBackendUser(self::EDITOR);
        $token = $this->decode($this->prepare(['source' => 'toolbar']))['draftToken'];

        $missingTitle = $this->submit(['draftToken' => $token, 'title' => ' ', 'action' => 'send']);
        self::assertSame(400, $missingTitle->getStatusCode());
        self::assertSame('titleMissing', $this->decode($missingTitle)['error']['code']);

        $expired = $this->submit(['draftToken' => 'forged' . str_repeat('a', 40), 'title' => 'Title']);
        self::assertSame(400, $expired->getStatusCode());
        self::assertSame('draftInvalid', $this->decode($expired)['error']['code']);

        $this->configureExtension(['reporting' => ['maxScreenshotSizeKb' => '100']]);
        $tooLarge = $this->submit(['draftToken' => $token, 'title' => 'Title'], false, str_repeat('x', 200 * 1024));
        self::assertSame(413, $tooLarge->getStatusCode());
        self::assertSame('screenshot.tooLarge', $this->decode($tooLarge)['error']['code']);

        $notAnImage = $this->submit(['draftToken' => $token, 'title' => 'Title'], false, '<svg xmlns="http://www.w3.org/2000/svg"/>');
        self::assertSame(400, $notAnImage->getStatusCode());
        self::assertSame('screenshot.format', $this->decode($notAnImage)['error']['code']);

        self::assertSame(0, $this->get(ReportRepository::class)->count());
    }

    /**
     * K5: the browser sends the same prepared report again after the answer was
     * lost; the server answers with the report it stored the first time.
     */
    #[Test]
    public function aSubmissionRepeatedAfterALostAnswerCreatesOneReport(): void
    {
        $this->configureExtension(['webhook' => ['enabled' => '1', 'url' => 'https://hooks.example.com/report']]);
        $this->respondWith(['reference' => 'SUP-1']);
        $this->loginBackendUser(self::EDITOR);
        $token = $this->decode($this->prepare(['source' => 'toolbar']))['draftToken'];

        $first = $this->decode($this->submit(['draftToken' => $token, 'title' => 'Teaser is broken', 'action' => 'send'], true));
        $again = $this->decode($this->submit(['draftToken' => $token, 'title' => 'Teaser is broken', 'action' => 'send'], true));

        self::assertSame($first['report']['identifier'], $again['report']['identifier']);
        self::assertSame($first['deliveries'], $again['deliveries']);
        self::assertSame('delivered', $again['deliveryState']);
        self::assertSame(1, $this->get(ReportRepository::class)->count());

        // Another user cannot use the draft
        $this->loginBackendUser(self::COLLEAGUE);
        $foreign = $this->submit(['draftToken' => $token, 'title' => 'Teaser is broken', 'action' => 'send']);
        self::assertSame(400, $foreign->getStatusCode());
        self::assertSame('draftInvalid', $this->decode($foreign)['error']['code']);
        self::assertSame(1, $this->get(ReportRepository::class)->count());
    }

    /**
     * The code of this version runs against tables of the previous one (the
     * schema update after the extension update was forgotten): nothing is sent
     * that could not be recorded, the reporter gets no error page, and the
     * retry after the update delivers exactly once.
     */
    #[Test]
    public function aMissingSchemaUpdateStopsDeliveriesBeforeAnythingIsSent(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(DeliveryAttemptRepository::TABLE);
        $connection->executeStatement(sprintf('ALTER TABLE %s DROP COLUMN %s', $connection->quoteIdentifier(DeliveryAttemptRepository::TABLE), $connection->quoteIdentifier('hub_receipt')));
        $this->get(DatabaseSchemaState::class)->reset();
        $this->configureExtension(['hub' => ['enabled' => '1', 'url' => 'https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P', 'secret' => 'whsec_8Fq2LmZx4RtY7vB1nKc3Wd9PaQ5sT0uV']]);
        $sent = $this->countRequests(['reference' => 'LW-152', 'url' => 'https://hub.example.com/app/reports/LW-152']);
        $this->loginBackendUser(self::ADMIN);

        $token = $this->decode($this->prepare(['source' => 'toolbar']))['draftToken'];
        $response = $this->submit(['draftToken' => $token, 'title' => 'Sent after an update', 'action' => 'send'], true);
        self::assertSame(200, $response->getStatusCode());
        $result = $this->decode($response);
        self::assertSame(0, $sent->count, 'Nothing left the installation');
        self::assertSame('failed', $result['deliveryState']);
        self::assertFalse($result['deliveries'][0]['successful']);
        $identifier = $result['report']['identifier'];
        $report = $this->get(ReportRepository::class)->findByIdentifier($identifier);
        self::assertNotNull($report);
        $attempts = $this->get(DeliveryAttemptRepository::class)->findByReportUid($report->uid);
        self::assertCount(1, $attempts);
        self::assertSame(DeliveryService::SCHEMA_OUTDATED_MESSAGE, $attempts[0]->message);

        // Submitting the same form again changes nothing and sends nothing
        $again = $this->decode($this->submit(['draftToken' => $token, 'title' => 'Sent after an update', 'action' => 'send'], true));
        self::assertSame($identifier, $again['report']['identifier']);
        self::assertSame(0, $sent->count);
        // Downloads and the screenshot still work, the retry waits for the update
        self::assertSame(200, $this->download($identifier, 'json')->getStatusCode());
        self::assertSame(200, $this->download($identifier, 'screenshot')->getStatusCode());
        try {
            $this->get(DeliveryService::class)->retry($report, 'hub', self::ADMIN);
            self::fail('The retry must wait for the schema update');
        } catch (DeliveryNotPossibleException $exception) {
            self::assertSame(DeliveryNotPossibleException::SCHEMA_OUTDATED, $exception->getReason());
        }
        self::assertSame(0, $sent->count);

        // What "Analyze Database Structure" / extension:setup adds
        $connection->executeStatement(sprintf('ALTER TABLE %s ADD %s TEXT', $connection->quoteIdentifier(DeliveryAttemptRepository::TABLE), $connection->quoteIdentifier('hub_receipt')));
        $this->get(DatabaseSchemaState::class)->reset();
        $attempt = $this->get(DeliveryService::class)->retry($report, 'hub', self::ADMIN);
        self::assertSame(1, $sent->count, 'Delivered exactly once');
        self::assertSame('LW-152', $attempt->externalReference);
        try {
            $this->get(DeliveryService::class)->retry($report, 'hub', self::ADMIN);
            self::fail('A delivered report is not sent again');
        } catch (DeliveryNotPossibleException $exception) {
            self::assertSame(DeliveryNotPossibleException::NOTHING_TO_RETRY, $exception->getReason());
        }
        self::assertSame(1, $sent->count);
    }

    #[Test]
    public function reportersCanDownloadTheirOwnReportsAndAdministratorsAllReports(): void
    {
        $this->loginBackendUser(self::EDITOR);
        $token = $this->decode($this->prepare(['source' => 'contextMenu', 'target' => ['type' => 'page', 'uid' => 2]]))['draftToken'];
        $identifier = $this->decode($this->submit(['draftToken' => $token, 'title' => 'Download me', 'action' => 'download'], true))['report']['identifier'];

        $json = $this->download($identifier, 'json');
        self::assertSame(200, $json->getStatusCode());
        self::assertSame('attachment; filename="' . $identifier . '.json"', $json->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $json->getHeaderLine('X-Content-Type-Options'));
        $payload = json_decode((string)$json->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($identifier, $payload['id']);
        self::assertSame(base64_encode((string)file_get_contents(self::SCREENSHOT)), $payload['attachments'][0]['contentBase64']);

        $screenshot = $this->download($identifier, 'screenshot');
        self::assertSame(200, $screenshot->getStatusCode());
        self::assertSame('image/png', $screenshot->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('inline;', $screenshot->getHeaderLine('Content-Disposition'));
        self::assertSame(file_get_contents(self::SCREENSHOT), (string)$screenshot->getBody());

        self::assertSame(400, $this->download($identifier, 'exe')->getStatusCode());
        self::assertSame(404, $this->download('CR-0000-0000-0000', 'json')->getStatusCode());
        self::assertSame(404, $this->download('../../etc/passwd', 'json')->getStatusCode());

        $this->loginBackendUser(self::COLLEAGUE);
        self::assertSame(404, $this->download($identifier, 'json')->getStatusCode());

        $this->loginBackendUser(self::ADMIN);
        $markdown = $this->download($identifier, 'markdown');
        self::assertSame(200, $markdown->getStatusCode());
        self::assertStringStartsWith("# Download me\n", (string)$markdown->getBody());

        $report = $this->get(ReportRepository::class)->findByIdentifier($identifier);
        self::assertNotNull($report);
        $downloads = $this->get(DeliveryAttemptRepository::class)->findByReportUid($report->uid);
        self::assertSame([['JSON', self::EDITOR], ['Markdown', self::ADMIN]], array_map(
            static fn($attempt): array => [$attempt->target, $attempt->triggeredBy],
            $downloads,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function submitReport(string $title): array
    {
        $token = $this->decode($this->prepare(['source' => 'contextMenu', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 10]]))['draftToken'];
        $response = $this->submit(['draftToken' => $token, 'title' => $title, 'description' => '', 'action' => 'send']);
        self::assertSame(200, $response->getStatusCode());
        return $this->decode($response);
    }

    /**
     * Answers every request like respondWith() and counts the requests.
     *
     * @param array<string, mixed> $body
     */
    private function countRequests(array $body): \stdClass
    {
        $counter = new \stdClass();
        $counter->count = 0;
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['contextReporterTest'] = static function (callable $next) use ($body, $counter): \Closure {
            return static function (RequestInterface $request, array $options) use ($body, $counter): PromiseInterface {
                $counter->count++;
                return Create::promiseFor(new Response(201, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));
            };
        };
        return $counter;
    }

    /**
     * Fails every request like the curl handler does when no answer arrives.
     */
    private function failWith(string $curlMessage): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['contextReporterTest'] = static function (callable $next) use ($curlMessage): \Closure {
            return static fn(RequestInterface $request, array $options): PromiseInterface => Create::rejectionFor(
                new \GuzzleHttp\Exception\ConnectException($curlMessage . ' (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for ' . $request->getUri(), $request),
            );
        };
    }

    /**
     * @param array<string, mixed> $body JSON answer of the webhook receiver
     */
    private function respondWith(array $body): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['contextReporterTest'] = static function (callable $next) use ($body): \Closure {
            return static fn(RequestInterface $request, array $options): PromiseInterface => Create::promiseFor(
                new Response(201, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)),
            );
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function prepare(array $data): ResponseInterface
    {
        return $this->callPrepare((string)json_encode($data));
    }

    private function callPrepare(string $body): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($body);
        $stream->rewind();
        $request = $GLOBALS['TYPO3_REQUEST']->withBody($stream)->withHeader('Content-Type', 'application/json');
        return $this->get(ReportAjaxController::class)->prepareAction($request);
    }

    /**
     * @param array<string, string> $fields
     */
    private function submit(array $fields, bool $withScreenshot = false, ?string $screenshotContent = null): ResponseInterface
    {
        $request = $this->createBackendRequest('/typo3/ajax/context-reporter/submit')->withParsedBody($fields);
        if ($withScreenshot || $screenshotContent !== null) {
            $stream = new Stream('php://temp', 'rw');
            $stream->write($screenshotContent ?? (string)file_get_contents(self::SCREENSHOT));
            $stream->rewind();
            $request = $request->withUploadedFiles([
                'screenshot' => new UploadedFile($stream, (int)$stream->getSize(), UPLOAD_ERR_OK, 'screenshot.png', 'image/png'),
            ]);
        }
        return $this->get(ReportAjaxController::class)->submitAction($request);
    }

    private function download(string $identifier, string $format): ResponseInterface
    {
        /** @var ServerRequestInterface $request */
        $request = $this->createBackendRequest('/typo3/context-reporter/download', 'GET')
            ->withQueryParams(['report' => $identifier, 'format' => $format]);
        return $this->get(ReportDownloadController::class)->downloadAction($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
