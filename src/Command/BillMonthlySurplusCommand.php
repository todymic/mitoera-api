<?php

namespace App\Command;

use App\Repository\SubscriptionRepository;
use App\Service\QuotaService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron: runs on the 1st of each month.
 *   0 6 1 * * php bin/console app:billing:surplus-monthly
 *
 * For each active subscription, calculates surplus seats for the previous month
 * and creates a Stripe invoice item if any.
 *
 * Safe to run multiple times — idempotent via (subscription_id, billed_month) unique key.
 */
#[AsCommand(
    name: 'app:billing:surplus-monthly',
    description: 'Bill surplus seats for the previous month for all active subscriptions',
)]
class BillMonthlySurplusCommand extends Command
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepo,
        private readonly QuotaService           $quota,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'month',
            null,
            InputOption::VALUE_OPTIONAL,
            'Month to bill in YYYY-MM format (default: last month)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $monthOption = $input->getOption('month');
        if ($monthOption) {
            $billedMonth = new \DateTimeImmutable($monthOption . '-01');
        } else {
            $billedMonth = new \DateTimeImmutable('first day of last month');
        }

        $io->title(sprintf('Monthly surplus billing — %s', $billedMonth->format('F Y')));

        $subscriptions = $this->subscriptionRepo->findAllActive();

        if (empty($subscriptions)) {
            $io->info('No active subscriptions found.');
            return Command::SUCCESS;
        }

        $io->progressStart(count($subscriptions));

        $billed  = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ($subscriptions as $sub) {
            try {
                $this->quota->billMonthlySurplus($sub, $billedMonth);
                $billed++;
            } catch (\Exception $e) {
                $io->error(sprintf(
                    'Failed for subscription %s (workspace %s): %s',
                    $sub->getId(),
                    $sub->getWorkspace()->getId(),
                    $e->getMessage()
                ));
                $errors++;
            }
            $io->progressAdvance();
        }

        $io->progressFinish();

        $io->success(sprintf(
            'Done. Processed: %d | Skipped (no surplus): %d | Errors: %d',
            $billed,
            $skipped,
            $errors,
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
