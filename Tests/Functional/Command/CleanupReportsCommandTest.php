<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use Priebera\ContextReporter\Domain\ContextDocument;
use Priebera\ContextReporter\Domain\Report;
use Priebera\ContextReporter\Domain\ReportSource;
use Priebera\ContextReporter\Domain\Repository\ReportRepository;
use Priebera\ContextReporter\Tests\Functional\AbstractContextReporterTestCase;
use Priebera\ContextReporter\Tests\Unit\Fixtures\ReportFixture;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;

final class CleanupReportsCommandTest extends AbstractContextReporterTestCase
{
    private const DAY = 86400;

    #[Test]
    public function commandIsRegisteredUnderItsName(): void
    {
        $registry = $this->get(CommandRegistry::class);

        self::assertTrue($registry->has('context-reporter:cleanup'));
        self::assertFalse($registry->has('contextreporter:cleanup'));
    }

    #[Test]
    public function dryRunShowsWhatWouldBeRemovedWithoutRemovingAnything(): void
    {
        $this->configureExtension(['reporting' => ['retentionDays' => 30]]);
        $this->addReport('CR-OLD0-0000-0001', time() - 40 * self::DAY);
        $this->addReport('CR-NEW0-0000-0001', time() - 10 * self::DAY);

        $tester = $this->runCommand(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $this->normalize($tester->getDisplay());
        self::assertStringContainsString('Retention: 30 days', $display);
        self::assertStringContainsString('Reports to remove: 1 (1 open)', $display);
        self::assertStringContainsString('Dry run: nothing was removed.', $display);
        self::assertSame(2, $this->get(ReportRepository::class)->count());
    }

    #[Test]
    public function configuredRetentionIsApplied(): void
    {
        $this->configureExtension(['reporting' => ['retentionDays' => 30]]);
        $this->addReport('CR-OLD0-0000-0001', time() - 40 * self::DAY);
        $this->addReport('CR-NEW0-0000-0001', time() - 10 * self::DAY);

        $tester = $this->runCommand([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Removed 1 report(s)', $this->normalize($tester->getDisplay()));
        self::assertNull($this->get(ReportRepository::class)->findByIdentifier('CR-OLD0-0000-0001'));
        self::assertNotNull($this->get(ReportRepository::class)->findByIdentifier('CR-NEW0-0000-0001'));
    }

    #[Test]
    public function keepForeverRemovesNoReportsUnlessDaysAreGiven(): void
    {
        $this->addReport('CR-OLD0-0000-0001', time() - 4000 * self::DAY);

        $tester = $this->runCommand([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Retention: keep forever', $this->normalize($tester->getDisplay()));
        self::assertStringContainsString('No reports were removed.', $this->normalize($tester->getDisplay()));
        self::assertSame(1, $this->get(ReportRepository::class)->count());

        $explicit = $this->runCommand(['--days' => '30']);
        self::assertSame(Command::SUCCESS, $explicit->getStatusCode());
        self::assertSame(0, $this->get(ReportRepository::class)->count());
    }

    #[Test]
    public function invalidDaysAreRejected(): void
    {
        $this->addReport('CR-OLD0-0000-0001', time() - 4000 * self::DAY);

        foreach (['0', '-5', 'abc', '99999'] as $days) {
            self::assertSame(Command::INVALID, $this->runCommand(['--days' => $days])->getStatusCode(), $days);
        }
        self::assertSame(1, $this->get(ReportRepository::class)->count());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(array $input): CommandTester
    {
        $tester = new CommandTester($this->get(CommandRegistry::class)->get('context-reporter:cleanup'));
        $tester->execute($input);
        return $tester;
    }

    private function normalize(string $display): string
    {
        return (string)preg_replace('/\s+/', ' ', $display);
    }

    private function addReport(string $identifier, int $createdAt): void
    {
        $this->get(ReportRepository::class)->add(new Report(
            identifier: $identifier,
            createdAt: new \DateTimeImmutable('@' . $createdAt),
            reporterUid: 3,
            source: ReportSource::Toolbar,
            title: 'Report ' . $identifier,
            description: '',
            document: ContextDocument::fromArray(ReportFixture::document()),
        ));
    }
}
