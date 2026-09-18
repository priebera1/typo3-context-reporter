<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Controller;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Controller\ReportAjaxController;
use Priebera\ContextReporter\Controller\ReportDownloadController;
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
