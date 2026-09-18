<?php

declare(strict_types=1);

namespace Priebera\ContextReporter\Command;

use Priebera\ContextReporter\Configuration\ExtensionSettingsFactory;
use Priebera\ContextReporter\Export\MarkdownReportExporter;
use Priebera\ContextReporter\Retention\CleanupPreview;
use Priebera\ContextReporter\Retention\RetentionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Applies the retention of the report history. Nothing is removed while the
 * retention is "keep forever", unless --days is given. Can be scheduled with
 * cron or the Scheduler task "Execute console commands".
 *
 * @internal
 */
#[AsCommand(
    name: 'context-reporter:cleanup',
    description: 'Removes stored reports older than the configured retention, including their screenshots and delivery history.',
)]
final class CleanupReportsCommand extends Command
{
    public function __construct(
        private readonly RetentionService $retention,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Remove reports created more than this many days ago instead of using the retention of the Context Reports settings.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be removed.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = $this->retention->getConfiguredDays();
        if ($input->getOption('days') !== null) {
            $days = filter_var($input->getOption('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => ExtensionSettingsFactory::MAX_RETENTION_DAYS]]);
            if ($days === false) {
                $io->error(sprintf('The option --days must be a number between 1 and %d.', ExtensionSettingsFactory::MAX_RETENTION_DAYS));
                return Command::INVALID;
            }
        }

        $preview = $this->retention->preview($days);
        $io->listing($this->describe($preview));

        if ($input->getOption('dry-run')) {
            $io->note('Dry run: nothing was removed.');
            return Command::SUCCESS;
        }
        if (!$preview->hasWork()) {
            $io->success('No reports were removed.');
            return Command::SUCCESS;
        }

        $result = $this->retention->cleanup($days);
        $io->success(sprintf(
            'Removed %d report(s) with their screenshots and delivery history, and %d orphaned row(s).',
            $result->reports,
            $result->orphanedAttachments + $result->orphanedDeliveries,
        ));
        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function describe(CleanupPreview $preview): array
    {
        if ($preview->cutoff === null) {
            $lines = ['Retention: keep forever (reports are only removed with --days)'];
        } else {
            $lines = [
                sprintf('Retention: %d days (reports created before %s)', $preview->days, $preview->cutoff->format('Y-m-d H:i T')),
                sprintf('Reports to remove: %d (%d open)', $preview->reports, $preview->openReports),
                sprintf('Screenshots to remove: %d (%s)', $preview->screenshots, MarkdownReportExporter::formatBytes($preview->screenshotBytes)),
                sprintf('Delivery attempts to remove: %d', $preview->deliveries),
            ];
        }
        $lines[] = sprintf('Orphaned rows: %d attachment(s), %d delivery attempt(s)', $preview->orphanedAttachments, $preview->orphanedDeliveries);
        return $lines;
    }
}
