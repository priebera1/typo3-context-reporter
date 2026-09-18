<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Delivery;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Delivery\Email\EmailTemplateLoader;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class EmailTemplateLoaderTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['typo3/cms-filelist'];

    protected array $testExtensionsToLoad = ['priebera/typo3-context-reporter'];

    protected function tearDown(): void
    {
        @unlink(Environment::getProjectPath() . '/.env');
        parent::tearDown();
    }

    #[Test]
    public function templatesOfExtensionsAreLoaded(): void
    {
        $template = (new EmailTemplateLoader())->load('EXT:context_reporter/Resources/Private/Templates/Email/Report.txt');

        self::assertStringContainsString('{report.title}', $template);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forbiddenPathProvider(): iterable
    {
        yield 'environment file' => ['.env'];
        yield 'absolute project file' => ['{project}/config/system/settings.php'];
        yield 'relative public file' => ['index.php'];
        yield 'extension file that is no template' => ['EXT:context_reporter/composer.json'];
        yield 'extension file outside of Resources/Private' => ['EXT:context_reporter/Documentation/Index.txt'];
        yield 'language file' => ['EXT:context_reporter/Resources/Private/Language/locallang.xlf'];
        yield 'path traversal' => ['EXT:context_reporter/Resources/Private/../../composer.txt'];
    }

    #[Test]
    #[DataProvider('forbiddenPathProvider')]
    public function onlyTextTemplatesInPrivateExtensionResourcesAreAllowed(string $path): void
    {
        $path = str_replace('{project}', Environment::getProjectPath(), $path);
        file_put_contents(Environment::getProjectPath() . '/.env', "DB_PASSWORD=secret-value\n");

        self::assertFalse(EmailTemplateLoader::isAllowedPath($path));
        $template = (new EmailTemplateLoader())->load($path);
        self::assertStringContainsString('{report.title}', $template, 'The default template is used instead');
        self::assertStringNotContainsString('secret-value', $template);
        self::assertStringNotContainsString('<?php', $template);
    }
}
