<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional;

use Priebera\ContextReporter\Configuration\ExtensionSettingsProvider;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Two sites' worth of pages and content, a file storage, an administrator, an
 * editor with a web mount on page 2 and a file mount on /user_upload/, an
 * editor who may not report and a second editor.
 */
abstract class AbstractContextReporterTestCase extends FunctionalTestCase
{
    protected const ADMIN = 1;
    protected const EDITOR = 2;
    protected const BLOCKED_EDITOR = 3;
    protected const COLLEAGUE = 4;
    /** Editor whose user TSconfig does not list the report action in options.file_list.primaryActions */
    protected const LIST_EDITOR = 6;

    /** sys_file 1: /user_upload/logo.png (metadata 7), 2: /private/budget.txt, 3: /user_upload/manual.pdf (missing) */
    protected const FILE_LOGO = 1;
    protected const FILE_BUDGET = 2;
    protected const FILE_MISSING = 3;

    protected array $coreExtensionsToLoad = ['typo3/cms-filelist'];

    protected array $testExtensionsToLoad = ['priebera/typo3-context-reporter'];

    protected array $configurationToUseInTestInstance = [
        'SYS' => ['sitename' => 'Functional Test Portal'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/files.csv');
        $this->createStorageFiles();
        // The first read of an unconfigured extension (e.g. "backend" while rendering a module) makes
        // TYPO3 synchronize all extension configuration, which drops values set by configureExtension()
        try {
            $this->get(ExtensionConfiguration::class)->get('backend');
        } catch (\Throwable) {
        }
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'https://www.example.com/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'navigationTitle' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'flag' => 'us'],
                ['languageId' => 1, 'title' => 'Deutsch', 'navigationTitle' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/', 'flag' => 'de'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['context_reporter'], $GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    /**
     * TYPO3 v14 renamed and regrouped the backend modules this extension reports
     * (see Documentation/Upgrade). Reports show the labels of the running Core,
     * so the expectations follow the installed branch.
     *
     * @return array{title: string, group: string}
     */
    protected static function coreModule(string $identifier): array
    {
        $modules = [
            'web_layout' => [['title' => 'Page', 'group' => 'Web'], ['title' => 'Layout', 'group' => 'Content']],
            'web_list' => [['title' => 'List', 'group' => 'Web'], ['title' => 'Records', 'group' => 'Content']],
            'media_management' => [['title' => 'Filelist', 'group' => 'File'], ['title' => 'Media', 'group' => '']],
            'site_configuration' => [['title' => 'Sites', 'group' => 'Site Management'], ['title' => 'Setup', 'group' => 'Sites']],
        ];
        if (!isset($modules[$identifier])) {
            throw new \InvalidArgumentException('Unknown backend module "' . $identifier . '".', 1758139200);
        }
        return $modules[$identifier][(new Typo3Version())->getMajorVersion() >= 14 ? 1 : 0];
    }

    /**
     * The module as the reports name it, e.g. "Web › Page" on TYPO3 v13.
     */
    protected static function coreModuleLabel(string $identifier): string
    {
        $module = self::coreModule($identifier);
        return implode(' › ', array_filter([$module['group'], $module['title']]));
    }

    protected function loginBackendUser(int $uid): BackendUserAuthentication
    {
        $backendUser = $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $GLOBALS['TYPO3_REQUEST'] = $this->createBackendRequest();
        // Storages carry the permissions of the user they were created for; a real request has one user only
        $this->get(StorageRepository::class)->flush();
        $this->get(CacheManager::class)->getCache('runtime')->flush();
        return $backendUser;
    }

    /**
     * @param array<string, mixed> $configuration System configuration overrides; missing values use the module settings or defaults
     */
    protected function configureExtension(array $configuration): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['context_reporter'] = $configuration;
        // Settings are cached per request; drop them so the new configuration applies
        $this->get(ExtensionSettingsProvider::class)->reset();
    }

    protected function createBackendRequest(string $path = '/typo3/ajax/context-reporter/prepare', string $method = 'POST'): ServerRequestInterface
    {
        $serverParams = [
            'HTTPS' => 'on',
            'HTTP_HOST' => 'backend.example.com',
            'SERVER_NAME' => 'backend.example.com',
            'SERVER_PORT' => '443',
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $path,
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => $this->instancePath . '/index.php',
            'PHP_SELF' => '/index.php',
            'DOCUMENT_ROOT' => $this->instancePath,
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'Functional test',
        ];
        $request = (new ServerRequest('https://backend.example.com' . $path, $method, 'php://temp', [], $serverParams))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    private function createStorageFiles(): void
    {
        $fileadmin = $this->instancePath . '/fileadmin';
        GeneralUtility::mkdir_deep($fileadmin . '/user_upload');
        GeneralUtility::mkdir_deep($fileadmin . '/private');
        copy(__DIR__ . '/../Unit/Fixtures/Images/screenshot.png', $fileadmin . '/user_upload/logo.png');
        file_put_contents($fileadmin . '/private/budget.txt', "Confidential budget 2026\n");
    }
}
