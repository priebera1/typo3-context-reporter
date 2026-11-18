<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Context\Subject\RecordAccess;
use Priebera\ContextReporter\Controller\ReportAjaxController;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\Stream;

/**
 * A09 (audit 2026-11-07): an explicitly chosen subject (context menu, list
 * actions, a crafted prepare request) in a language the reporter may not use
 * ("Allowed languages" of the backend groups) is not available, with the same
 * answer as any object the reporter may not see, and none of its metadata.
 * permissions.csv: page 60 "Locked page" with its German translation 62
 * "Gesperrte Seite", content 92 (default) and its German translation 94
 * "Gesperrtes Markup". language_access.csv: content 95 for all languages (-1);
 * Greta [41] is a reader (group 14, page 60) who may also use German (0,1). Rita [16] may use English (0).
 */
final class LanguageAccessTest extends AbstractContextReporterTestCase
{
    private const RITA = 16;
    private const GRETA = 41;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/permissions.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/language_access.csv');
    }

    /**
     * @return \Generator<string, array{int, array<string, mixed>, bool}>
     */
    public static function subjects(): \Generator
    {
        $germanPage = ['type' => 'page', 'uid' => 62];
        $germanContent = ['type' => 'record', 'table' => 'tt_content', 'uid' => 94];
        $germanPageAsRecord = ['type' => 'record', 'table' => 'pages', 'uid' => 62];
        $defaultPage = ['type' => 'page', 'uid' => 60];
        $defaultContent = ['type' => 'record', 'table' => 'tt_content', 'uid' => 92];
        $allLanguages = ['type' => 'record', 'table' => 'tt_content', 'uid' => 95];
        yield 'English only: German page translation' => [self::RITA, $germanPage, false];
        yield 'English only: German content element' => [self::RITA, $germanContent, false];
        yield 'English only: German page translation as record' => [self::RITA, $germanPageAsRecord, false];
        yield 'English only: default language page' => [self::RITA, $defaultPage, true];
        yield 'English only: default language content' => [self::RITA, $defaultContent, true];
        yield 'English only: content for all languages' => [self::RITA, $allLanguages, true];
        yield 'English and German: German page translation' => [self::GRETA, $germanPage, true];
        yield 'English and German: German content element' => [self::GRETA, $germanContent, true];
        yield 'English and German: default language content' => [self::GRETA, $defaultContent, true];
        yield 'English and German: content for all languages' => [self::GRETA, $allLanguages, true];
        yield 'Administrator: German content element' => [self::ADMIN, $germanContent, true];
    }

    /**
     * @param array<string, mixed> $target
     */
    #[Test]
    #[DataProvider('subjects')]
    public function anExplicitSubjectIsPreparedOnlyInAnAllowedLanguage(int $user, array $target, bool $available): void
    {
        $backendUser = $this->loginBackendUser($user);
        $response = $this->prepare(['source' => 'contextMenu', 'target' => $target]);
        $body = (string)$response->getBody();

        if (!$available) {
            self::assertSame(404, $response->getStatusCode(), $body);
            foreach (['Gesperrt', 'draftToken', '"uid":' . $target['uid']] as $leak) {
                self::assertStringNotContainsString($leak, $body, 'Nothing of the translation is revealed');
            }
            $recordAccess = $this->get(RecordAccess::class);
            $found = $target['type'] === 'page'
                ? $recordAccess->findPage((int)$target['uid'], $backendUser)
                : $recordAccess->findRecord((string)$target['table'], (int)$target['uid'], $backendUser);
            self::assertNull($found);
            return;
        }
        self::assertSame(200, $response->getStatusCode(), $body);
        self::assertStringContainsString('draftToken', $body);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function prepare(array $data): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write((string)json_encode($data));
        $stream->rewind();
        $request = $GLOBALS['TYPO3_REQUEST']->withBody($stream)->withHeader('Content-Type', 'application/json');
        return $this->get(ReportAjaxController::class)->prepareAction($request);
    }
}
