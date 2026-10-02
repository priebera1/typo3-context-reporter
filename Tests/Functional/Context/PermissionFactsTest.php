<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Context;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\CollectionRequest;
use Priebera\ContextReporter\Context\ContextDocumentBuilder;
use Priebera\ContextReporter\Context\Location\BackendLocationFactory;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * "context.permissions": permission facts of the reporter, never a decision.
 * The fixture (permissions.csv) adds below "About" [2] a page locked for
 * editing [60] that the group "Readers" may only show, with a locked German
 * HTML element [92]; and a page [61] whose TSconfig disables "header" for all
 * and "subheader" for text elements, with a text element [93]. Rita [16] is a
 * reader: no table may be modified, English only, text elements only, the
 * file mount /user_upload/ read-only, file permissions read, write and add
 * (the user records have no file permissions of their own).
 * Paul [17] is an editor who may also use text and HTML elements and
 * standard pages.
 */
final class PermissionFactsTest extends AbstractContextReporterTestCase
{
    private const RITA = 16;
    private const PAUL = 17;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/permissions.csv');
    }

    #[Test]
    public function grantedPermissionsOfAnEditor(): void
    {
        $this->loginBackendUser(self::PAUL);

        $permissions = $this->permissions(['type' => 'record', 'table' => 'tt_content', 'uid' => 10]);

        self::assertSame(['modify' => true], $permissions['table'] ?? null);
        self::assertSame(['uid' => 2, 'show' => true, 'editPage' => true, 'deletePage' => true, 'newPages' => true, 'editContent' => true], $permissions['page'] ?? null);
        self::assertSame(['id' => 0, 'allowed' => true], $permissions['language'] ?? null);
        self::assertSame([['field' => 'CType', 'value' => 'text', 'allowed' => true]], $permissions['recordType'] ?? null);
        self::assertArrayNotHasKey('editLock', $permissions);
        // Paul may edit no exclude field, e.g. "Hidden"
        self::assertContains(['field' => 'hidden', 'label' => $this->englishLabel('tt_content', 'hidden')], $permissions['fields']['notAllowed'] ?? []);
    }

    #[Test]
    public function missingPermissionsAndLocksAreFacts(): void
    {
        $this->loginBackendUser(self::RITA);

        $permissions = $this->permissions(['type' => 'record', 'table' => 'tt_content', 'uid' => 92]);

        self::assertSame(['modify' => false], $permissions['table'] ?? null);
        self::assertSame(['uid' => 60, 'show' => true, 'editPage' => false, 'deletePage' => false, 'newPages' => false, 'editContent' => false], $permissions['page'] ?? null);
        self::assertSame(['page' => true, 'record' => true], $permissions['editLock'] ?? null);
        self::assertSame(['id' => 1, 'allowed' => false], $permissions['language'] ?? null);
        self::assertSame([['field' => 'CType', 'value' => 'html', 'allowed' => false]], $permissions['recordType'] ?? null);
        self::assertNotContains('hidden', array_column($permissions['fields']['notAllowed'] ?? [], 'field'), 'Rita may edit "Hidden"');
    }

    #[Test]
    public function pagesHaveTheirOwnPermissionsAndPageType(): void
    {
        $this->loginBackendUser(self::RITA);
        $page = $this->permissions(['type' => 'page', 'uid' => 60]);
        self::assertSame(['modify' => false], $page['table'] ?? null);
        self::assertSame(['page' => true], $page['editLock'] ?? null);
        self::assertSame(['doktype' => 1, 'allowed' => false], $page['pageType'] ?? null);

        $this->loginBackendUser(self::PAUL);
        self::assertSame(['doktype' => 1, 'allowed' => true], $this->permissions(['type' => 'page', 'uid' => 2])['pageType'] ?? null);
    }

    #[Test]
    public function fieldsDisabledInTsconfigAndFieldsOfTheDefaultLanguageAreListed(): void
    {
        $this->loginBackendUser(self::PAUL);

        $fields = $this->permissions(['type' => 'record', 'table' => 'tt_content', 'uid' => 93])['fields'] ?? [];
        self::assertSame(['header', 'subheader'], array_column($fields['disabled'] ?? [], 'field'), '"bodytext" is only disabled for HTML elements');

        $translation = $this->permissions(['type' => 'record', 'table' => 'tt_content', 'uid' => 12])['fields'] ?? [];
        self::assertContains('colPos', array_column($translation['defaultLanguageOnly'] ?? [], 'field'), 'The column of a translation follows the default language');
        self::assertArrayNotHasKey('defaultLanguageOnly', $this->permissions(['type' => 'record', 'table' => 'tt_content', 'uid' => 10])['fields'] ?? []);
    }

    #[Test]
    public function newRecordsHaveTableAndPagePermissions(): void
    {
        $this->loginBackendUser(self::RITA);

        $request = CollectionRequest::fromArray(['source' => 'formEngine', 'location' => ['url' => '/typo3/record/edit?edit[tt_content][60]=new']], new BackendLocationFactory());
        $permissions = $this->get(ContextDocumentBuilder::class)->build($request, $GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'])->toArray()['context']['permissions'] ?? [];

        self::assertSame(['table', 'page'], array_keys($permissions));
        self::assertFalse($permissions['page']['editContent']);
    }

    #[Test]
    public function filePermissionsAreTheReportersFileActionsAndMounts(): void
    {
        $this->loginBackendUser(self::RITA);
        self::assertSame(
            [
                'fileActions' => ['read' => true, 'write' => true, 'rename' => false, 'replace' => false, 'move' => false, 'copy' => false, 'delete' => false],
                'writableFileMount' => false,
                'storageWritable' => true,
            ],
            $this->permissions(['type' => 'file', 'uid' => self::FILE_LOGO]),
        );
        self::assertSame(
            [
                'folderActions' => ['read' => true, 'write' => false, 'add' => false, 'rename' => false, 'move' => false, 'copy' => false, 'delete' => false],
                'fileActions' => ['add' => true],
                'writableFileMount' => false,
                'storageWritable' => true,
            ],
            $this->permissions(['type' => 'folder', 'identifier' => '1:/user_upload/']),
        );

        $this->loginBackendUser(self::EDITOR);
        self::assertTrue($this->permissions(['type' => 'file', 'uid' => self::FILE_LOGO])['writableFileMount'] ?? null);
    }

    #[Test]
    public function administratorsHaveNoPermissionFacts(): void
    {
        $this->loginBackendUser(self::ADMIN);

        foreach ([['type' => 'record', 'table' => 'tt_content', 'uid' => 92], ['type' => 'page', 'uid' => 60], ['type' => 'file', 'uid' => self::FILE_LOGO]] as $target) {
            self::assertSame([], $this->permissions($target));
        }
    }

    #[Test]
    public function permissionFactsNameNoOtherUsersOrGroups(): void
    {
        $this->loginBackendUser(self::RITA);

        $json = json_encode($this->permissions(['type' => 'record', 'table' => 'tt_content', 'uid' => 92]), JSON_THROW_ON_ERROR);

        foreach (['Readers', 'Rita', 'perms_', 'usergroup', 'owner', 'Workspace'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $json);
        }
    }

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private function permissions(array $target): array
    {
        $request = CollectionRequest::fromArray(['source' => 'contextMenu', 'target' => $target], new BackendLocationFactory());
        return $this->get(ContextDocumentBuilder::class)->build($request, $GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'])->toArray()['context']['permissions'] ?? [];
    }

    private function englishLabel(string $table, string $field): string
    {
        return $this->get(LanguageServiceFactory::class)->create('en')->sL($GLOBALS['TCA'][$table]['columns'][$field]['label']);
    }
}
