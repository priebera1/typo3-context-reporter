<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Context;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\ContextDocumentBuilder;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * "context.fileUsage": where a reported file is used. The fixture
 * (file_usage.csv) references logo.png [1] from the element "Hero teaser"
 * [10] (once hidden [164], once deleted [165]), from the element on the other
 * root [11], from the media of the page "About" [2], from the avatar of the
 * administrator, from the German translation [12] and, in workspace A only,
 * from the element [13]; budget.txt [2] from "Hero teaser".
 */
final class FileUsageTest extends AbstractContextReporterTestCase
{
    private const WORKSPACE_A = 1;

    protected array $coreExtensionsToLoad = ['typo3/cms-filelist', 'typo3/cms-workspaces'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/workspaces.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/file_usage.csv');
    }

    #[Test]
    public function usagesAreListedWhereTheReporterMayAccessTheRecordAndField(): void
    {
        $this->loginBackendUser(self::EDITOR);
        $images = $this->englishLabel('tt_content', 'image');
        $about = ['uid' => 2, 'title' => 'About'];

        self::assertSame(
            [
                'references' => 6,
                'usages' => [
                    ['table' => 'tt_content', 'uid' => 10, 'label' => 'Hero teaser', 'field' => 'image', 'fieldLabel' => $images, 'reference' => 160, 'page' => $about],
                    ['table' => 'tt_content', 'uid' => 10, 'label' => 'Hero teaser', 'field' => 'image', 'fieldLabel' => $images, 'reference' => 164, 'page' => $about, 'hidden' => true],
                    ['table' => 'tt_content', 'uid' => 12, 'label' => 'Hero Teaser DE', 'field' => 'image', 'fieldLabel' => $images, 'reference' => 166, 'page' => $about],
                ],
                // The element on the other root, the page media (no permission for the field) and the avatar
                'notAccessible' => 3,
            ],
            $this->fileUsage(self::FILE_LOGO),
        );
    }

    #[Test]
    public function administratorsSeeAllUsagesExceptUsersAndGroups(): void
    {
        $this->loginBackendUser(self::ADMIN);

        $usage = $this->fileUsage(self::FILE_LOGO);

        self::assertSame([160, 161, 162, 164, 166], array_column($usage['usages'] ?? [], 'reference'));
        self::assertSame(['table' => 'pages', 'uid' => 2, 'label' => 'About', 'field' => 'media', 'fieldLabel' => $this->englishLabel('pages', 'media'), 'reference' => 162], $usage['usages'][2] ?? null);
        self::assertSame(1, $usage['notAccessible'] ?? null, 'Backend user avatars are never listed');
        self::assertStringNotContainsString('admin', json_encode($usage, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function usagesFollowTheWorkspaceOfTheReporter(): void
    {
        $this->loginInWorkspace(self::EDITOR, self::WORKSPACE_A);

        $usage = $this->fileUsage(self::FILE_LOGO);

        self::assertSame(7, $usage['references'] ?? null);
        self::assertSame([160, 164, 166, 167], array_column($usage['usages'] ?? [], 'reference'));
        // The element [10] has a draft in workspace A
        self::assertSame('Hero teaser draft A', $usage['usages'][0]['label'] ?? null);
    }

    #[Test]
    public function listedAndCheckedUsagesAreCapped(): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference');
        for ($uid = 300; $uid < 410; $uid++) {
            $connection->insert('sys_file_reference', ['uid' => $uid, 'pid' => 2, 'uid_local' => self::FILE_BUDGET, 'uid_foreign' => 10, 'tablenames' => 'tt_content', 'fieldname' => 'image', 'sorting_foreign' => $uid]);
        }
        $this->loginBackendUser(self::ADMIN);

        $usage = $this->fileUsage(self::FILE_BUDGET);

        self::assertSame(111, $usage['references'] ?? null);
        self::assertCount(10, $usage['usages'] ?? []);
        self::assertSame(90, $usage['notListed'] ?? null);
        self::assertSame(11, $usage['notChecked'] ?? null);
    }

    #[Test]
    public function unusedFilesAndOtherSubjectsHaveNoUsage(): void
    {
        $this->loginBackendUser(self::ADMIN);

        self::assertSame(['references' => 0], $this->fileUsage(self::FILE_MISSING));
        foreach ([['type' => 'page', 'uid' => 2], ['type' => 'record', 'table' => 'sys_file_metadata', 'uid' => 7]] as $target) {
            self::assertArrayNotHasKey('fileUsage', $this->context($target));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fileUsage(int $fileUid): array
    {
        $context = $this->context(['type' => 'file', 'uid' => $fileUid]);
        self::assertIsArray($context['fileUsage'] ?? null);
        return $context['fileUsage'];
    }

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private function context(array $target): array
    {
        $request = CollectionRequest::fromArray(['source' => 'contextMenu', 'target' => $target], new BackendLocationFactory());
        return $this->get(ContextDocumentBuilder::class)->build($request, $GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'])->toArray()['context'] ?? [];
    }

    private function englishLabel(string $table, string $field): string
    {
        return $this->get(LanguageServiceFactory::class)->create('en')->sL($GLOBALS['TCA'][$table]['columns'][$field]['label']);
    }

    private function loginInWorkspace(int $userUid, int $workspace): void
    {
        $backendUser = $this->loginBackendUser($userUid);
        $backendUser->setWorkspace($workspace);
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect($workspace));
    }
}
