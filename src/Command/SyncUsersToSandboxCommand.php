<?php

namespace App\Command;

use App\Message\SyncUserToSandboxMessage;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:sync-users-to-sandbox',
    description: 'Sync all prod users to the sandbox database (one-shot migration)',
)]
class SyncUsersToSandboxCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Required to actually run the sync');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('force')) {
            $io->warning('Pass --force to actually dispatch the sync messages.');
            return Command::SUCCESS;
        }

        $users = $this->userRepository->findAll();
        $io->progressStart(count($users));

        foreach ($users as $user) {
            $this->messageBus->dispatch(new SyncUserToSandboxMessage($user->getId()));
            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->success(sprintf('Dispatched sync for %d users.', count($users)));

        return Command::SUCCESS;
    }
}
