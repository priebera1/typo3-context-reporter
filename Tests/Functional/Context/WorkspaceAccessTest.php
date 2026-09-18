<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\ContextDocumentBuilder;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Context\Subject\SubjectNotAvailableException;
use Priebera\ContextReporter\Domain\ContextDocument;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;

/**
 * Reports may only describe the workspace state the reporter works in:
 * versions and new records of other workspaces are never revealed, and
 * offline version UIDs of the own workspace resolve to the record they
 * belong to. The editor is a member of workspace A only.
 */
final class WorkspaceAccessTest extends AbstractContextReporterTestCase
{
    private const LIVE = 0;
    private const WORKSPACE_A = 1;
    private const WORKSPACE_B = 2;

    protected array $coreExtensionsToLoad = ['typo3/cms-filelist', 'typo3/cms-workspaces'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/workspaces.csv');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function draftTargetProvider(): iterable
    {
        yield 'version of a record' => [['type' => 'record', 'table' => 'tt_content', 'uid' => 30]];
        yield 'version of a record in another workspace' => [['type' => 'record', 'table' => 'tt_content', 'uid' => 31]];
        yield 'new record' => [['type' => 'record', 'table' => 'tt_content', 'uid' => 32]];
        yield 'new record of another workspace' => [['type' => 'record', 'table' => 'tt_content', 'uid' => 33]];
        yield 'record on a new page' => [['type' => 'record', 'table' => 'tt_content', 'uid' => 34]];
        yield 'version of a page' => [['type' => 'page', 'uid' => 40]];
        yield 'version of a page in another workspace' => [['type' => 'page', 'uid' => 41]];
        yield 'new page' => [['type' => 'page', 'uid' => 42]];
        yield 'new page of another workspace' => [['type' => 'page', 'uid' => 43]];
        yield 'page version as record' => [['type' => 'record', 'table' => 'pages', 'uid' => 40]];
    }

    /**
     * @param array<string, mixed> $target
     */
    #[Test]
    #[DataProvider('draftTargetProvider')]
    public function liveUsersCannotReportDraftsOrNewWorkspaceRecords(array $target): void
    {
        $this->loginInWorkspace(self::EDITOR, self::LIVE);

        $this->expectException(SubjectNotAvailableException::class);
        $this->build(['source' => 'contextMenu', 'target' => $target]);
    }

    #[Test]
    public function liveUsersReportTheLiveState(): void
    {
        $this->loginInWorkspace(self::EDITOR, self::LIVE);

        $record = $this->build(['source' => 'contextMenu', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 10]])->toArray();
        $page = $this->build(['source' => 'contextMenu', 'target' => ['type' => 'page', 'uid' => 2]])->toArray();

        self::assertSame('Hero teaser', $record['subject']['label']);
        self::assertSame('About', $page['subject']['label']);
        self::assertSame('Live', $record['context']['workspace']['title']);
        $this->assertNoDraftData($record, $page);
    }

    #[Test]
    public function editingFormsAndPageTreesOfLiveUsersRevealNoDrafts(): void
    {
        $this->loginInWorkspace(self::EDITOR, self::LIVE);

        foreach ([
            ['url' => '/typo3/record/edit?edit%5Btt_content%5D%5B30%5D=edit', 'pageTreeSelection' => '2'],
            ['url' => '/typo3/record/edit?edit%5Btt_content%5D%5B33%2C32%5D=edit', 'pageTreeSelection' => '2'],
            ['url' => '/typo3/record/edit?edit%5Bpages%5D%5B41%5D=edit'],
            ['url' => '/typo3/record/edit?edit%5Btt_content%5D%5B-31%5D=new'],
            ['url' => '/typo3/module/web/layout?id=42', 'module' => 'web_layout'],
            ['url' => '/typo3/module/web/list', 'module' => 'web_list', 'pageTreeSelection' => '43'],
        ] as $location) {
            $data = $this->build(['source' => 'toolbar', 'location' => $location])->toArray();

            $this->assertNoDraftData($data);
            self::assertNotContains($data['subject']['uid'] ?? 0, [30, 31, 32, 33, 40, 41, 42, 43], $location['url']);
        }
    }

    #[Test]
    public function workspaceMembersReportTheVersionsOfTheirWorkspace(): void
    {
        $this->loginInWorkspace(self::EDITOR, self::WORKSPACE_A);

        $record = $this->build(['source' => 'contextMenu', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 10]])->toArray();
        self::assertSame(10, $record['subject']['uid']);
        self::assertSame('Hero teaser draft A', $record['subject']['label']);
        self::assertSame(30, $record['context']['record']['workspaceVersionUid']);
        self::assertSame('Workspace A', $record['context']['workspace']['title']);

        $version = $this->build(['source' => 'contextMenu', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 30]])->toArray();
        self::assertSame(10, $version['subject']['uid'], 'An offline version UID resolves to its record');
        self::assertSame('Hero teaser draft A', $version['subject']['label']);
        self::assertSame(30, $version['context']['record']['workspaceVersionUid']);

        $page = $this->build(['source' => 'contextMenu', 'target' => ['type' => 'page', 'uid' => 40]])->toArray();
        self::assertSame(2, $page['subject']['uid']);
        self::assertSame('About draft A', $page['subject']['label']);
        self::assertSame(40, $page['context']['page']['workspaceVersionUid']);

        $newRecord = $this->build(['source' => 'contextMenu', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 32]])->toArray();
        self::assertSame(32, $newRecord['subject']['uid']);
        self::assertSame('New content in A', $newRecord['subject']['label']);

        $newPage = $this->build(['source' => 'contextMenu', 'target' => ['type' => 'page', 'uid' => 42]])->toArray();
        self::assertSame(42, $newPage['subject']['uid']);
        self::assertSame('New page in A', $newPage['subject']['label']);
        self::assertSame([['uid' => 2, 'title' => 'About draft A'], ['uid' => 42, 'title' => 'New page in A']], $newPage['context']['page']['rootline']);

        $recordOnNewPage = $this->build(['source' => 'contextMenu', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 34]])->toArray();
        self::assertSame(42, $recordOnNewPage['context']['record']['pid']);
        self::assertSame('New page in A', $recordOnNewPage['context']['page']['title']);

        $this->assertNoDataOfWorkspaceB($record, $version, $page, $newRecord, $newPage, $recordOnNewPage);
    }

    #[Test]
    public function editingFormsOfWorkspaceMembersUseTheRecordsOfTheirWorkspace(): void
    {
        $this->loginInWorkspace(self::EDITOR, self::WORKSPACE_A);

        $data = $this->build([
            'source' => 'formEngine',
            'location' => ['url' => '/typo3/record/edit?edit%5Btt_content%5D%5B30%2C31%2C32%5D=edit'],
        ])->toArray();

        self::assertSame(10, $data['subject']['uid']);
        self::assertSame([
            ['table' => 'tt_content', 'uid' => 10],
            ['table' => 'tt_content', 'uid' => 32],
        ], $data['context']['formEngine']['records']);
        $this->assertNoDataOfWorkspaceB($data);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function otherWorkspaceTargetProvider(): iterable
    {
        yield 'version of a record' => [['type' => 'record', 'table' => 'tt_content', 'uid' => 31]];
        yield 'new record' => [['type' => 'record', 'table' => 'tt_content', 'uid' => 33]];
        yield 'version of a page' => [['type' => 'page', 'uid' => 41]];
        yield 'new page' => [['type' => 'page', 'uid' => 43]];
    }

    /**
     * @param array<string, mixed> $target
     */
    #[Test]
    #[DataProvider('otherWorkspaceTargetProvider')]
    public function workspaceMembersCannotReportRecordsOfOtherWorkspaces(array $target): void
    {
        $this->loginInWorkspace(self::EDITOR, self::WORKSPACE_A);

        $this->expectException(SubjectNotAvailableException::class);
        $this->build(['source' => 'contextMenu', 'target' => $target]);
    }

    #[Test]
    public function editorsCannotSwitchToWorkspacesTheyAreNotAMemberOf(): void
    {
        $backendUser = $this->loginBackendUser(self::EDITOR);
        $backendUser->setWorkspace(self::WORKSPACE_B);

        self::assertNotSame(self::WORKSPACE_B, (int)$backendUser->workspace);
    }

    #[Test]
    public function administratorsReportTheVersionsOfTheirCurrentWorkspaceOnly(): void
    {
        $this->loginInWorkspace(self::ADMIN, self::WORKSPACE_B);

        $data = $this->build(['source' => 'contextMenu', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 31]])->toArray();
        self::assertSame(10, $data['subject']['uid']);
        self::assertSame('Hero teaser draft B secret', $data['subject']['label']);

        $this->expectException(SubjectNotAvailableException::class);
        $this->build(['source' => 'contextMenu', 'target' => ['type' => 'record', 'table' => 'tt_content', 'uid' => 30]]);
    }

    private function loginInWorkspace(int $userUid, int $workspace): void
    {
        $backendUser = $this->loginBackendUser($userUid);
        $backendUser->setWorkspace($workspace);
        self::assertSame($workspace, (int)$backendUser->workspace);
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect($workspace));
    }

    /**
     * @param array<string, mixed> ...$documents
     */
    private function assertNoDraftData(array ...$documents): void
    {
        $json = json_encode($documents, JSON_THROW_ON_ERROR);
        foreach (['draft', 'secret', 'New content', 'New page', 'Content on new page', 'Workspace A', 'Workspace B'] as $text) {
            self::assertStringNotContainsString($text, $json);
        }
    }

    /**
     * @param array<string, mixed> ...$documents
     */
    private function assertNoDataOfWorkspaceB(array ...$documents): void
    {
        $json = json_encode($documents, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret', $json);
        self::assertStringNotContainsString('Workspace B', $json);
        self::assertStringNotContainsString('"uid":31', $json);
        self::assertStringNotContainsString('"uid":33', $json);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function build(array $input): ContextDocument
    {
        $request = CollectionRequest::fromArray($input, new BackendLocationFactory());
        return $this->get(ContextDocumentBuilder::class)->build($request, $GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST']);
    }
}
