<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Backend\BackendLinkBuilder;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Controller\ReportDownloadController;
use Priebera\ContextReporter\Controller\ReportModuleController;
use Priebera\ContextReporter\Controller\ReportSettingsController;
use Priebera\ContextReporter\Domain\DeliveryAttempt;
use Priebera\ContextReporter\Domain\DeliveryStatus;
use Priebera\ContextReporter\Domain\Repository\DeliveryAttemptRepository;
use Priebera\ContextReporter\Domain\Repository\ReportRepository;
use Priebera\ContextReporter\Domain\ReviewState;
use Priebera\ContextReporter\Report\ReportService;
use Priebera\ContextReporter\Report\ReportSubmission;
use Priebera\ContextReporter\Security\ReportAccessPolicy;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;

/**
 * K1: who may use System > Context Reports. Administrators: everything. Editors without the module: report
 * problems, nothing of the module. Editors an administrator granted the module (core's "Modules" access of
 * their group): the report history and its review actions, never the settings, the connection tests or
 * deleting reports.
 */
final class ModuleAccessTest extends AbstractContextReporterTestCase
{
    use ModuleRequestTrait;

    #[Test]
    public function coreModuleAccessFollowsTheGrantedModules(): void
    {
        $moduleProvider = $this->get(ModuleProvider::class);
        $policy = $this->get(ReportAccessPolicy::class);
        foreach ([self::ADMIN => true, self::EDITOR => false, self::REVIEWER => true] as $uid => $expected) {
            $user = $this->loginBackendUser($uid);
            self::assertSame($expected, $moduleProvider->accessGranted(BackendLinkBuilder::REPORT_MODULE, $user), 'core, user ' . $uid);
            self::assertSame($expected, $policy->canUseReportsModule($user), 'policy, user ' . $uid);
            self::assertTrue($policy->canReport($user), 'everyone may report, user ' . $uid);
        }
    }

    #[Test]
    public function editorWithoutTheModuleGetsNothingOfIt(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $identifier = $this->createReport('Teaser is broken');
        $this->loginBackendUser(self::EDITOR);

        foreach ($this->moduleRoutes($identifier) as $route => $response) {
            self::assertSame(403, $response()->getStatusCode(), $route);
        }
        foreach ($this->settingsRoutes() as $route => $response) {
            self::assertSame(403, $response()->getStatusCode(), $route);
        }
        // The answers are TYPO3 module pages that say why, not bare text (Erika's backend is German)
        $denied = (string)$this->get(ReportModuleController::class)->indexAction($this->createModuleRequest('system_contextreports'))->getBody();
        self::assertStringContainsString('Kein Zugriff auf Context Reports', $denied);
        self::assertStringContainsString('Zugriffslisten › Module', $denied);
        self::assertStringNotContainsString('Zurück zu den Berichten', $denied);
        // Downloads do not reveal whether another user's report exists.
        self::assertSame(404, $this->download($identifier)->getStatusCode(), 'another user\'s report');
    }

    #[Test]
    public function editorWithTheModuleUsesTheHistoryButNeverTheSettings(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $identifier = $this->createReport('Teaser is broken');
        $this->loginBackendUser(self::REVIEWER);
        $controller = $this->get(ReportModuleController::class);

        $index = $controller->indexAction($this->createModuleRequest('system_contextreports'));
        self::assertSame(200, $index->getStatusCode());
        $html = (string)$index->getBody();
        self::assertStringContainsString('Teaser is broken', $html);
        self::assertStringNotContainsString('context-reports/settings', $html, 'no Settings button or link');
        self::assertStringNotContainsString('context-reports/testHub', $html);

        $show = (string)$controller->showAction($this->createModuleRequest('system_contextreports.show', ['report' => $identifier]))->getBody();
        self::assertStringContainsString('Teaser is broken', $show);
        self::assertStringNotContainsString('cr-delete-form', $show, 'deleting is for administrators');
        self::assertStringNotContainsString('context-reports/settings', $show);

        self::assertSame(303, $this->post('resolve', ['report' => $identifier])->getStatusCode());
        self::assertSame(ReviewState::Resolved, $this->get(ReportRepository::class)->findByIdentifier($identifier)?->reviewState);
        self::assertSame(303, $this->post('reopen', ['report' => $identifier])->getStatusCode());
        $delete = $this->post('delete', ['report' => $identifier]);
        self::assertSame(403, $delete->getStatusCode());
        self::assertStringContainsString('Deleting reports is for administrators', (string)$delete->getBody());
        self::assertNotNull($this->get(ReportRepository::class)->findByIdentifier($identifier));

        foreach ($this->settingsRoutes() as $route => $response) {
            $answer = $response();
            self::assertSame(403, $answer->getStatusCode(), $route);
            self::assertStringContainsString('The settings are for administrators', (string)$answer->getBody(), $route);
            self::assertStringContainsString('Back to the reports', (string)$answer->getBody(), $route);
        }
        // The screenshot and exports of the reports shown in the history can be opened.
        self::assertSame(200, $this->download($identifier)->getStatusCode());
    }

    /**
     * Where reports go (the address of a webhook or the Hub, the number of email recipients) is for
     * administrators, in the report detail as in the history and the report dialog. The delivery itself, its
     * result and the format of a download stay visible to everyone who may use the history.
     */
    #[Test]
    public function onlyAdministratorsSeeWhereTheDeliveriesOfAReportWent(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $identifier = $this->createReport('Teaser is broken');
        $report = $this->get(ReportRepository::class)->findByIdentifier($identifier);
        self::assertNotNull($report);
        $attempts = $this->get(DeliveryAttemptRepository::class);
        $attempts->add(new DeliveryAttempt($report->uid, 'webhook', 1, DeliveryStatus::Failed, new \DateTimeImmutable(), 'https://n8n.internal.example:5678', self::ADMIN, 503, 'HTTP 503: Service Unavailable'));
        $attempts->add(new DeliveryAttempt($report->uid, 'hub', 1, DeliveryStatus::Succeeded, new \DateTimeImmutable(), 'http://hub.internal.example:8080', self::ADMIN, 200, 'HTTP 200', 'LW-152'));
        $attempts->add(new DeliveryAttempt($report->uid, 'email', 1, DeliveryStatus::Succeeded, new \DateTimeImmutable(), '7 recipients', self::ADMIN));
        $attempts->add(new DeliveryAttempt($report->uid, DeliveryAttempt::DESTINATION_DOWNLOAD, 1, DeliveryStatus::Succeeded, new \DateTimeImmutable(), 'markdown', self::ADMIN));
        $show = fn(): string => (string)$this->get(ReportModuleController::class)->showAction($this->createModuleRequest('system_contextreports.show', ['report' => $identifier]))->getBody();

        $html = $show();
        self::assertStringContainsString('https://n8n.internal.example:5678', $html);
        self::assertStringContainsString('http://hub.internal.example:8080', $html);
        self::assertStringContainsString('7 recipients', $html);

        $this->loginBackendUser(self::REVIEWER);
        $html = $show();
        self::assertStringNotContainsString('internal.example', $html, 'no webhook or Hub address');
        self::assertStringNotContainsString('7 recipients', $html);
        // The deliveries themselves stay: their result, the answer and the Hub reference
        self::assertStringContainsString('HTTP 503: Service Unavailable', $html);
        self::assertStringContainsString('LW-152', $html);
        self::assertMatchesRegularExpression('~<span>markdown</span>~', $html, 'the format of a download is no address');
    }

    #[Test]
    public function administratorsKeepEverything(): void
    {
        $this->loginBackendUser(self::ADMIN);
        $identifier = $this->createReport('Teaser is broken');
        foreach ($this->moduleRoutes($identifier) as $route => $response) {
            self::assertContains($response()->getStatusCode(), [200, 303], $route);
        }
        $settings = $this->get(ReportSettingsController::class)->settingsAction($this->createModuleRequest('system_contextreports.settings', ['tab' => 'general']));
        self::assertSame(200, $settings->getStatusCode());
        self::assertStringContainsString('context-reports/settings', (string)$this->get(ReportModuleController::class)->indexAction($this->createModuleRequest('system_contextreports'))->getBody());
        self::assertSame(303, $this->post('delete', ['report' => $identifier])->getStatusCode());
    }

    /**
     * @return array<string, \Closure(): ResponseInterface>
     */
    private function moduleRoutes(string $identifier): array
    {
        $controller = $this->get(ReportModuleController::class);
        return [
            'index' => fn() => $controller->indexAction($this->createModuleRequest('system_contextreports')),
            'show' => fn() => $controller->showAction($this->createModuleRequest('system_contextreports.show', ['report' => $identifier])),
            'resolve' => fn() => $this->post('resolve', ['report' => $identifier]),
            'reopen' => fn() => $this->post('reopen', ['report' => $identifier]),
        ];
    }

    /**
     * @return array<string, \Closure(): ResponseInterface>
     */
    private function settingsRoutes(): array
    {
        $controller = $this->get(ReportSettingsController::class);
        $request = fn(string $route, ?array $body = null) => $this->createModuleRequest('system_contextreports.' . $route, $route === 'settings' ? ['tab' => 'general'] : [], $body);
        return [
            'settings' => fn() => $controller->settingsAction($request('settings')),
            'saveSettings' => fn() => $controller->saveSettingsAction($request('saveSettings', ['tab' => 'general', 'settings' => ['enabled' => '0']])),
            'testEmail' => fn() => $controller->testEmailAction($request('testEmail', [])),
            'testHub' => fn() => $controller->testHubAction($request('testHub', [])),
            'testWebhook' => fn() => $controller->testWebhookAction($request('testWebhook', [])),
            'cleanup' => fn() => $controller->cleanupAction($request('cleanup', ['days' => '0'])),
        ];
    }

    /**
     * @param array<string, string> $body
     */
    private function post(string $action, array $body): ResponseInterface
    {
        return $this->get(ReportModuleController::class)->{$action . 'Action'}($this->createModuleRequest('system_contextreports.' . $action, body: $body));
    }

    private function download(string $identifier): ResponseInterface
    {
        $request = $this->createBackendRequest('/typo3/context-reporter/download', 'GET')->withQueryParams(['report' => $identifier, 'format' => 'json']);
        return $this->get(ReportDownloadController::class)->downloadAction($request);
    }

    private function createReport(string $title): string
    {
        $service = $this->get(ReportService::class);
        $prepared = $service->prepare(CollectionRequest::fromArray(['source' => 'recordList', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 10]], new BackendLocationFactory()), $GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST']);
        return $service->submit(new ReportSubmission($prepared->draftToken, $title, '', null, false), $GLOBALS['BE_USER'])->report->identifier;
    }
}
