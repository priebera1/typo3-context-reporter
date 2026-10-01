<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Unit\Context\Visibility;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\ContextReporter\Context\Visibility\EnableFields;
use Priebera\ContextReporter\Context\Visibility\VisibilityEvaluator;

final class VisibilityEvaluatorTest extends TestCase
{
    private const NOW = 1790000000;

    private VisibilityEvaluator $evaluator;
    private EnableFields $fields;

    protected function setUp(): void
    {
        $this->evaluator = new VisibilityEvaluator();
        $this->fields = new EnableFields('hidden', 'starttime', 'endtime', 'fe_group');
    }

    #[Test]
    public function recordWithoutRestrictingSettingsHasNoReasons(): void
    {
        $visibility = $this->evaluator->evaluate(['hidden' => 0, 'starttime' => 0, 'endtime' => 0, 'fe_group' => ''], $this->fields, self::NOW);

        self::assertFalse($visibility->hidden);
        self::assertSame(0, $visibility->startTime);
        self::assertSame(0, $visibility->endTime);
        self::assertSame([], $visibility->frontendGroups);
        self::assertSame([], $visibility->reasons);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function settingsProvider(): iterable
    {
        yield 'hidden' => [['hidden' => 1], [VisibilityEvaluator::HIDDEN]];
        yield 'start in the future' => [['starttime' => self::NOW + 1], [VisibilityEvaluator::SCHEDULED]];
        yield 'start now' => [['starttime' => self::NOW], []];
        yield 'start in the past' => [['starttime' => self::NOW - 1], []];
        yield 'end in the past' => [['endtime' => self::NOW - 1], [VisibilityEvaluator::EXPIRED]];
        yield 'end now' => [['endtime' => self::NOW], [VisibilityEvaluator::EXPIRED]];
        yield 'end in the future' => [['endtime' => self::NOW + 1], []];
        yield 'frontend group' => [['fe_group' => '3'], [VisibilityEvaluator::ACCESS_RESTRICTED]];
        yield 'hide at login' => [['fe_group' => '-1'], [VisibilityEvaluator::ACCESS_RESTRICTED]];
        yield 'show at any login' => [['fe_group' => '-2'], [VisibilityEvaluator::ACCESS_RESTRICTED]];
        yield 'no group' => [['fe_group' => '0'], []];
        yield 'everything' => [
            ['hidden' => 1, 'starttime' => self::NOW + 60, 'endtime' => self::NOW - 60, 'fe_group' => '2'],
            [VisibilityEvaluator::HIDDEN, VisibilityEvaluator::SCHEDULED, VisibilityEvaluator::EXPIRED, VisibilityEvaluator::ACCESS_RESTRICTED],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('settingsProvider')]
    public function reasonsFollowTheRulesTypo3AppliesToVisitors(array $row, array $expected): void
    {
        self::assertSame($expected, $this->evaluator->evaluate($row, $this->fields, self::NOW)->reasons);
    }

    #[Test]
    public function frontendGroupsAreParsedFromTheStoredList(): void
    {
        $visibility = $this->evaluator->evaluate(['fe_group' => ' 4, -1,0,x,4,-2 ,12'], $this->fields, self::NOW);

        self::assertSame([4, -1, -2, 12], $visibility->frontendGroups);
    }

    #[Test]
    public function fieldsTheTableDoesNotDeclareAreIgnored(): void
    {
        $visibility = $this->evaluator->evaluate(
            ['hidden' => 1, 'starttime' => self::NOW + 1, 'endtime' => self::NOW - 1, 'fe_group' => '1'],
            new EnableFields(),
            self::NOW,
        );

        self::assertSame([], $visibility->reasons);
        self::assertSame(0, $visibility->startTime);
    }

    #[Test]
    public function onlyPagesThatExtendTheirRestrictionsRestrictSubpages(): void
    {
        $restricted = ['hidden' => 0, 'fe_group' => '2', 'extendToSubpages' => 1];
        $visibility = $this->evaluator->evaluate($restricted, $this->fields, self::NOW);
        self::assertTrue($this->evaluator->restrictsSubpages($restricted, $visibility, 'extendToSubpages'));

        $notExtended = ['fe_group' => '2', 'extendToSubpages' => 0];
        self::assertFalse($this->evaluator->restrictsSubpages($notExtended, $this->evaluator->evaluate($notExtended, $this->fields, self::NOW), 'extendToSubpages'));

        $unrestricted = ['extendToSubpages' => 1];
        self::assertFalse($this->evaluator->restrictsSubpages($unrestricted, $this->evaluator->evaluate($unrestricted, $this->fields, self::NOW), 'extendToSubpages'));

        self::assertFalse($this->evaluator->restrictsSubpages($restricted, $visibility, ''));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int, string|null}>
     */
    public static function workspaceRowProvider(): iterable
    {
        yield 'live workspace' => [['uid' => 10, 't3ver_wsid' => 0, 't3ver_state' => 0], 0, null];
        yield 'unchanged live record' => [['uid' => 10, 't3ver_wsid' => 0, 't3ver_state' => 0], 1, VisibilityEvaluator::WORKSPACE_UNCHANGED];
        yield 'changed' => [['uid' => 10, '_ORIG_uid' => 30, 't3ver_wsid' => 1, 't3ver_state' => 0], 1, VisibilityEvaluator::WORKSPACE_CHANGED];
        yield 'moved' => [['uid' => 10, '_ORIG_uid' => 30, 't3ver_wsid' => 1, 't3ver_state' => 4], 1, VisibilityEvaluator::WORKSPACE_CHANGED];
        yield 'deleted' => [['uid' => 10, '_ORIG_uid' => 30, 't3ver_wsid' => 1, 't3ver_state' => 2], 1, VisibilityEvaluator::WORKSPACE_DELETED];
        yield 'new' => [['uid' => 32, 't3ver_wsid' => 1, 't3ver_state' => 1], 1, VisibilityEvaluator::WORKSPACE_NEW];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[Test]
    #[DataProvider('workspaceRowProvider')]
    public function workspaceStateIsReadFromTheOverlaidRow(array $row, int $workspace, ?string $expected): void
    {
        self::assertSame($expected, $this->evaluator->getWorkspaceState($row, $workspace));
    }
}
