<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Controller;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Priebera\ContextReporter\Configuration\SettingsRepository;
use Priebera\ContextReporter\Controller\ReportSettingsController;
use Priebera\ContextReporter\Delivery\Webhook\WebhookSignature;
use Priebera\ContextReporter\Domain\ContextDocument;
use Priebera\ContextReporter\Domain\Report;
use Priebera\ContextReporter\Domain\ReportSource;
use Priebera\ContextReporter\Domain\Repository\DeliveryAttemptRepository;
use Priebera\ContextReporter\Domain\Repository\ReportRepository;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use Priebera\ContextReporter\Tests\Unit\Fixtures\ReportFixture;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Registry;

final class ReportSettingsControllerTest extends AbstractContextReporterTestCase
{
    use ModuleRequestTrait;

    private const HUB_URL = 'https://hub.example.com/in/s_8Fq2LmZx4RtY7vB1nKc3Wd9P';
    private const HUB_SECRET = 'whsec_8Fq2LmZx4RtY7vB1nKc3Wd9PaQ5sT0uV';

    /**
     * @var list<RequestInterface>
     */
    private array $hubRequests = [];

    /**
     * @var array<string, mixed>
     */
    private array $mailConfiguration = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailConfiguration = $GLOBALS['TYPO3_CONF_VARS']['MAIL'];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = $this->mailConfiguration;
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['contextReporterTest']);
        putenv('CR_TEST_TOKEN');
        parent::tearDown();
    }

    #[Test]
    public function onlyAdministratorsCanUseTheSettings(): void
    {
        $this->loginBackendUser(self::EDITOR);
        $controller = $this->get(ReportSettingsController::class);

        self::assertSame(403, $controller->settingsAction($this->createModuleRequest('system_contextreports.settings'))->getStatusCode());
        $body = ['tab' => 'general', 'settings' => ['enabled' => '0'], 'days' => '0'];
        self::assertSame(403, $controller->saveSettingsAction($this->createModuleRequest('system_contextreports.saveSettings', body: $body))->getStatusCode());
        self::assertSame(403, $controller->testEmailAction($this->createModuleRequest('system_contextreports.testEmail', body: $body))->getStatusCode());
        self::assertSame(403, $controller->testWebhookAction($this->createModuleRequest('system_contextreports.testWebhook', body: $body))->getStatusCode());
        self::assertSame(403, $controller->testHubAction($this->createModuleRequest('system_contextreports.testHub', body: $body))->getStatusCode());
        self::assertSame(403, $controller->cleanupAction($this->createModuleRequest('system_contextreports.cleanup', body: $body))->getStatusCode());
        self::assertSame([], $this->get(SettingsRepository::class)->load());
    }

    #[Test]
    public function generalSettingsAreShownAndSaved(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $html = $this->renderSettings('general');
        self::assertStringContainsString('Not saved in this module yet.', $html);
        self::assertStringContainsString('name="settings[projectName]"', $html);
        self::assertStringContainsString('placeholder="Functional Test Portal"', $html);
        self::assertStringContainsString('<option value="" selected>Automatic (', $html);

        $response = $this->post('saveSettings', [
            'tab' => 'general',
            'settings' => ['enabled' => '1', 'projectName' => ' Agency Portal ', 'projectIdentifier' => 'portal', 'environment' => 'custom', 'environmentCustom' => 'QA'],
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('tab=general', $response->getHeaderLine('Location'));
        self::assertSame('The settings were saved.', $this->getFlashMessages()[0]['message']);
        self::assertSame(
            ['enabled' => true, 'projectName' => 'Agency Portal', 'projectIdentifier' => 'portal', 'environment' => 'QA'],
            $this->get(SettingsRepository::class)->load()['general'],
        );
        self::assertSame('QA', $this->get(ExtensionSettingsProvider::class)->get()->environment);

        $html = $this->renderSettings('general');
        self::assertStringContainsString('Last saved on', $html);
        self::assertStringContainsString('by Alice Admin (admin)', $html);
        self::assertStringContainsString('value="Agency Portal"', $html);
        self::assertStringContainsString('<option value="custom" selected>', $html);
        self::assertStringContainsString('value="QA"', $html);
    }

    #[Test]
    public function rejectedInputIsShownAgainAndNotSaved(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $response = $this->post('saveSettings', [
            'tab' => 'email',
            'settings' => ['enabled' => '1', 'recipients' => 'support@example.com, not-an-address', 'subject' => 'Report'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $html = (string)$response->getBody();
        self::assertStringContainsString('The settings were not saved. Please correct the marked fields.', $html);
        self::assertStringContainsString('Enter valid email addresses, separated by commas or line breaks.', $html);
        self::assertStringContainsString('>support@example.com, not-an-address</textarea>', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertArrayNotHasKey('email', $this->get(SettingsRepository::class)->load());
    }

    #[Test]
    public function webhookSecretsAreWriteOnly(): void
    {
        putenv('CR_TEST_TOKEN=Bearer env-token-value');
        $this->saveSettings('webhook', [
            'enabled' => true,
            'url' => 'https://hooks.example.com/hook/s3cr3t-path',
            'secret' => 'hmac-secret-value',
            'authHeaderName' => 'Authorization',
            'authHeaderValue' => '%env(CR_TEST_TOKEN)%',
        ]);
        $this->loginBackendUser(self::ADMIN);

        $html = $this->renderSettings('webhook');

        self::assertStringContainsString('https://hooks.example.com/…', $html);
        self::assertStringContainsString('Configured via environment (CR_TEST_TOKEN)', $html);
        self::assertStringContainsString('Leave empty to keep the current value', $html);
        self::assertStringContainsString('autocomplete="new-password"', $html);
        foreach (['s3cr3t-path', 'hmac-secret-value', 'env-token-value'] as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }

        $this->post('saveSettings', [
            'tab' => 'webhook',
            'settings' => ['enabled' => '1', 'url' => '', 'secret' => '', 'authHeaderName' => 'Authorization', 'authHeaderValue' => '', 'timeout' => '12', 'includeScreenshot' => '0', 'allowInsecureHttp' => '0'],
        ]);

        $stored = $this->get(SettingsRepository::class)->load()['webhook'];
        self::assertSame('https://hooks.example.com/hook/s3cr3t-path', $stored['url']);
        self::assertSame('hmac-secret-value', $stored['secret']);
        self::assertSame('%env(CR_TEST_TOKEN)%', $stored['authHeaderValue']);
        self::assertSame(12, $stored['timeout']);
        self::assertFalse($stored['includeScreenshot']);
    }

    #[Test]
    public function rejectedWebhookFormsDoNotEchoSecrets(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $response = $this->post('saveSettings', [
            'tab' => 'webhook',
            'settings' => ['enabled' => '1', 'url' => 'ftp://hooks.example.com/typed-path-token', 'secret' => 'typed-secret-value', 'authHeaderName' => 'Authorization', 'authHeaderValue' => 'Bearer typed-token-value', 'timeout' => '10'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $html = (string)$response->getBody();
        self::assertStringContainsString('Secret values that you entered were not kept', $html);
        foreach (['typed-path-token', 'typed-secret-value', 'typed-token-value'] as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }
        self::assertArrayNotHasKey('webhook', $this->get(SettingsRepository::class)->load());
    }

    #[Test]
    public function emailStatusExplainsTheMailConfigurationWithoutCredentials(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = array_replace($GLOBALS['TYPO3_CONF_VARS']['MAIL'], [
            'transport' => 'smtp',
            'transport_smtp_server' => 'smtp.example.com:587',
            'transport_smtp_username' => 'mailer-account',
            'transport_smtp_password' => 'mailer-password',
            'defaultMailFromAddress' => '',
        ]);
        $this->loginBackendUser(self::ADMIN);

        $html = $this->renderSettings('email');

        self::assertStringContainsString('Email delivery is switched off: reports are not sent by email.', $html);
        self::assertStringContainsString('No recipients are configured.', $html);
        self::assertStringContainsString('SMTP smtp.example.com:587 (with authentication)', $html);
        self::assertStringContainsString('Save at least one recipient to send a test email.', $html);
        self::assertMatchesRegularExpression('/<button type="submit" class="btn btn-default" disabled/', $html);
        self::assertStringNotContainsString('mailer-account', $html);
        self::assertStringNotContainsString('mailer-password', $html);
    }

    #[Test]
    public function testEmailIsHandedOverToTheMailTransport(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = 'null';
        $this->saveSettings('email', ['enabled' => false, 'recipients' => 'support@example.com']);
        $this->loginBackendUser(self::ADMIN);

        $response = $this->post('testEmail', []);

        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('tab=email', $response->getHeaderLine('Location'));
        $message = $this->getFlashMessages()[0];
        self::assertSame('Test email handed over to the mail transport', $message['title']);
        self::assertStringContainsString('support@example.com', $message['message']);
        self::assertSame('WARNING', $message['severity'], 'The null transport discards emails');
    }

    #[Test]
    public function testWebhookIsPostedToTheSavedEndpointAndNotRecorded(): void
    {
        $requests = [];
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['contextReporterTest'] = static function (callable $next) use (&$requests): \Closure {
            return static function (RequestInterface $request, array $options) use (&$requests) {
                $requests[] = $request;
                return Create::promiseFor(new Response(202));
            };
        };
        $this->saveSettings('webhook', ['enabled' => false, 'url' => 'https://hooks.example.com/hook', 'secret' => 'hmac-secret-value']);
        $this->loginBackendUser(self::ADMIN);

        $response = $this->post('testWebhook', []);

        self::assertSame(303, $response->getStatusCode());
        $message = $this->getFlashMessages()[0];
        self::assertSame('The endpoint accepted the test webhook', $message['title']);
        self::assertSame('Response: HTTP 202', $message['message']);
        self::assertCount(1, $requests);
        self::assertSame('test', $requests[0]->getHeaderLine('X-Context-Reporter-Event'));
        self::assertSame(0, $this->getConnectionPool()->getConnectionForTable(DeliveryAttemptRepository::TABLE)->count('*', DeliveryAttemptRepository::TABLE, []));
    }

    #[Test]
    public function cleanupAppliesOnlyTheSavedRetention(): void
    {
        $this->get(ReportRepository::class)->add(new Report(
            identifier: 'CR-OLD0-0000-0001',
            createdAt: new \DateTimeImmutable('-400 days'),
            reporterUid: 1,
            source: ReportSource::Toolbar,
            title: 'Old report',
            description: '',
            document: ContextDocument::fromArray(ReportFixture::document()),
        ));
        $this->loginBackendUser(self::ADMIN);

        self::assertStringContainsString('Reports are kept forever, so the cleanup removes no reports.', $this->renderSettings('reporting'));
        $this->post('cleanup', ['days' => '30']);
        self::assertSame('WARNING', $this->getFlashMessages()[0]['severity']);
        self::assertNotNull($this->get(ReportRepository::class)->findByIdentifier('CR-OLD0-0000-0001'));

        $this->saveSettings('reporting', ['retentionDays' => 30]);
        $html = $this->renderSettings('reporting');
        self::assertStringContainsString('Reports are kept for 30 days.', $html);
        self::assertStringContainsString('Remove old reports (1)…', $html);
        self::assertStringContainsString('<option value="30" selected>', $html);
        // Without the confirmation script, a click must not submit the form
        self::assertMatchesRegularExpression('/<form id="cr-cleanup-form" action="[^"]+" method="post">/', $html);
        self::assertMatchesRegularExpression('/<button\s+type="button"\s+class="btn btn-danger t3js-modal-trigger"\s+data-target-form="cr-cleanup-form"/', $html);
        // TYPO3 v14 only reads data-content; data-bs-content confirms with a generic question
        self::assertMatchesRegularExpression('/data-content="[^"]+"/', $html);
        self::assertStringNotContainsString('data-bs-content', $html);

        $response = $this->post('cleanup', ['days' => '30']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('Cleanup finished. Removed reports: 1, removed orphaned entries: 0.', $this->getFlashMessages()[0]['message']);
        self::assertNull($this->get(ReportRepository::class)->findByIdentifier('CR-OLD0-0000-0001'));
    }

    #[Test]
    public function valuesOfTheSystemConfigurationAreLocked(): void
    {
        $this->configureExtension(['email' => ['enabled' => '1', 'recipients' => 'ops@example.com']]);
        $this->loginBackendUser(self::ADMIN);

        $html = $this->renderSettings('email');

        self::assertStringContainsString('Some settings are set in the TYPO3 system configuration', $html);
        self::assertStringContainsString('callout callout-info', $html, 'The notice keeps its severity on both TYPO3 branches');
        self::assertStringContainsString('email.enabled, email.recipients', $html);
        self::assertStringContainsString('Set in the TYPO3 system configuration and cannot be changed here.', $html);
        self::assertMatchesRegularExpression('/name="settings\[recipients\]"[^>]*\bdisabled\b/s', $html);

        $response = $this->post('saveSettings', [
            'tab' => 'email',
            'settings' => ['enabled' => '0', 'recipients' => '', 'senderAddress' => 'portal@example.com'],
        ]);

        self::assertSame(303, $response->getStatusCode());
        $stored = $this->get(SettingsRepository::class)->load()['email'];
        self::assertArrayNotHasKey('enabled', $stored);
        self::assertArrayNotHasKey('recipients', $stored);
        self::assertSame('portal@example.com', $stored['senderAddress']);
        $settings = $this->get(ExtensionSettingsProvider::class)->get()->email;
        self::assertTrue($settings->enabled);
        self::assertSame(['ops@example.com'], $settings->recipients);
    }

    #[Test]
    public function hubValuesAreWriteOnlyAndTheConnectionIsExplained(): void
    {
        $this->saveSettings('hub', ['enabled' => true, 'url' => self::HUB_URL, 'secret' => self::HUB_SECRET, 'includeScreenshot' => true]);
        $this->loginBackendUser(self::ADMIN);

        $html = $this->renderSettings('hub');

        self::assertStringContainsString('Send reports to Context Reporter Hub', $html);
        self::assertStringContainsString('https://hub.example.com/…', $html);
        self::assertStringContainsString('Not tested yet', $html);
        self::assertStringContainsString('Test connection', $html);
        self::assertStringContainsString('Leave empty to keep the current value', $html);
        self::assertStringNotContainsString('btn btn-default" disabled', $html);
        self::assertStringNotContainsString('s_8Fq2LmZx4RtY7vB1nKc3Wd9P', $html);
        self::assertStringNotContainsString('whsec_8Fq2', $html);

        $this->post('saveSettings', ['tab' => 'hub', 'settings' => ['enabled' => '1', 'url' => '', 'secret' => '', 'includeScreenshot' => '0', 'allowInsecureHttp' => '0']]);

        $stored = $this->get(SettingsRepository::class)->load()['hub'];
        self::assertSame(self::HUB_URL, $stored['url']);
        self::assertSame(self::HUB_SECRET, $stored['secret']);
        self::assertFalse($stored['includeScreenshot']);
    }

    #[Test]
    public function rejectedHubFormsExplainTheValuesAndDoNotEchoThem(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $response = $this->post('saveSettings', [
            'tab' => 'hub',
            'settings' => ['enabled' => '1', 'url' => 'whsec_typedSecretInUrlField000000001', 'secret' => 'https://hub.example.com/in/s_typedUrlInSecretField00001'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $html = (string)$response->getBody();
        self::assertStringContainsString('Enter the webhook URL of a site in Context Reporter Hub', $html);
        self::assertStringContainsString('Enter the signing secret of the Hub site (whsec_…)', $html);
        self::assertStringContainsString('Secret values that you entered were not kept', $html);
        self::assertStringNotContainsString('typedSecretInUrlField', $html);
        self::assertStringNotContainsString('typedUrlInSecretField', $html);
        self::assertArrayNotHasKey('hub', $this->get(SettingsRepository::class)->load());
    }

    #[Test]
    public function hubConnectionTestIsRememberedForTheSavedValues(): void
    {
        $this->respondToHub(new Response(200, ['Content-Type' => 'application/json; charset=utf-8'], '{"status":"ok","message":"Test delivery received. This site is connected to Context Reporter Hub."}'));
        $this->saveSettings('hub', ['enabled' => false, 'url' => self::HUB_URL, 'secret' => self::HUB_SECRET]);
        $this->loginBackendUser(self::ADMIN);

        $response = $this->post('testHub', []);

        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('tab=hub', $response->getHeaderLine('Location'));
        $message = $this->getFlashMessages()[0];
        self::assertSame('Connected to Context Reporter Hub', $message['title']);
        self::assertStringContainsString('accepted the signed test delivery (HTTP 200)', $message['message']);
        self::assertSame('OK', $message['severity']);
        self::assertCount(1, $this->hubRequests);
        [$request] = $this->hubRequests;
        self::assertSame(self::HUB_URL, (string)$request->getUri());
        self::assertSame('test', $request->getHeaderLine('X-Context-Reporter-Event'));
        self::assertTrue(WebhookSignature::verify($request->getHeaderLine(WebhookSignature::HEADER), self::HUB_SECRET, (string)$request->getBody()));
        self::assertSame(0, $this->getConnectionPool()->getConnectionForTable(DeliveryAttemptRepository::TABLE)->count('*', DeliveryAttemptRepository::TABLE, []));
        self::assertStringContainsString('Accepted by the Hub', $this->renderSettings('hub'));

        $this->saveSettings('hub', ['enabled' => false, 'url' => self::HUB_URL, 'secret' => 'whsec_rotatedSecretOfTheSite00000000001']);
        self::assertStringContainsString('Not tested with the current webhook URL and secret', $this->renderSettings('hub'));
        // The remembered result does not contain the URL or the secret
        $remembered = json_encode($this->get(Registry::class)->get(SettingsRepository::REGISTRY_NAMESPACE, 'hubConnectionTest'), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"successful":true', $remembered);
        self::assertStringNotContainsString('whsec_', $remembered);
        self::assertStringNotContainsString('s_8Fq2LmZx4RtY7vB1nKc3Wd9P', $remembered);
    }

    #[Test]
    public function failedHubConnectionTestExplainsTheCauseWithoutSecrets(): void
    {
        $this->respondToHub(new Response(401, ['Content-Type' => 'application/json; charset=utf-8'], '{"error":"invalid_signature","message":"The signature does not match. Expected ' . self::HUB_SECRET . ' at ' . self::HUB_URL . '"}'));
        $this->saveSettings('hub', ['enabled' => true, 'url' => self::HUB_URL, 'secret' => self::HUB_SECRET]);
        $this->loginBackendUser(self::ADMIN);

        $this->post('testHub', []);

        $message = $this->getFlashMessages()[0];
        self::assertSame('The Hub rejected the signature', $message['title']);
        self::assertSame('ERROR', $message['severity']);
        self::assertStringContainsString('Copy the current signing secret of the site from Context Reporter Hub', $message['message']);
        self::assertStringContainsString('HTTP 401: {"error":"invalid_signature"', $message['message']);
        $html = $this->renderSettings('hub');
        self::assertStringContainsString('The Hub rejected the signature', $html);
        foreach ([$message['message'], $html] as $output) {
            self::assertStringNotContainsString('whsec_8Fq2', $output);
            self::assertStringNotContainsString('s_8Fq2LmZx4RtY7vB1nKc3Wd9P', $output);
        }
    }

    #[Test]
    public function hubConnectionTestFromTheReportHistoryReturnsThere(): void
    {
        $this->respondToHub(new Response(200, ['Content-Type' => 'application/json; charset=utf-8'], '{"status":"ok"}'));
        $this->saveSettings('hub', ['enabled' => true, 'url' => self::HUB_URL, 'secret' => self::HUB_SECRET]);
        $this->loginBackendUser(self::ADMIN);

        $response = $this->post('testHub', ['returnTo' => 'index']);

        self::assertSame(303, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringContainsString('/module/system/context-reports?', $location);
        self::assertStringNotContainsString('settings', $location);
        self::assertSame('Connected to Context Reporter Hub', $this->getFlashMessages()[0]['title']);
        // Any other value keeps the settings as the target
        self::assertStringContainsString('tab=hub', $this->post('testHub', ['returnTo' => 'https://evil.example.com/'])->getHeaderLine('Location'));
    }

    #[Test]
    public function hubConnectionTestNeedsSavedValues(): void
    {
        $this->respondToHub(new Response(200));
        $this->loginBackendUser(self::ADMIN);

        self::assertStringContainsString('Save the webhook URL and the signing secret of a Hub site to test the connection.', $this->renderSettings('hub'));
        $this->post('testHub', []);

        self::assertSame('Save the webhook URL and the signing secret of a Hub site to test the connection.', $this->getFlashMessages()[0]['message']);
        self::assertSame([], $this->hubRequests);
    }

    #[Test]
    public function settingsOfADevelopmentVersionAreExplained(): void
    {
        $this->configureExtension(['general' => ['enabled' => '1', 'maxScreenshotSizeKb' => '5120']]);
        $this->loginBackendUser(self::ADMIN);

        self::assertStringContainsString('Settings of a development version take precedence', $this->renderSettings('general'));
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

    private function renderSettings(string $tab): string
    {
        $response = $this->get(ReportSettingsController::class)->settingsAction($this->createModuleRequest('system_contextreports.settings', ['tab' => $tab]));
        self::assertSame(200, $response->getStatusCode());
        return (string)$response->getBody();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $route, array $body): ResponseInterface
    {
        $controller = $this->get(ReportSettingsController::class);
        $request = $this->createModuleRequest('system_contextreports.' . $route, body: $body);
        return match ($route) {
            'saveSettings' => $controller->saveSettingsAction($request),
            'testEmail' => $controller->testEmailAction($request),
            'testWebhook' => $controller->testWebhookAction($request),
            'testHub' => $controller->testHubAction($request),
            'cleanup' => $controller->cleanupAction($request),
            default => throw new \InvalidArgumentException('Unknown route ' . $route, 1758400001),
        };
    }

    /**
     * @param array<string, mixed> $values
     */
    private function saveSettings(string $section, array $values): void
    {
        $this->get(SettingsRepository::class)->saveSection($section, $values, self::ADMIN);
        $this->get(ExtensionSettingsProvider::class)->reset();
    }
}
