<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Amorçage du premier administrateur.
 *
 * `app:user:create-backoffice` ne suffit pas : elle accorde ROLE_BACKOFFICE
 * alors que /api/admin/* exige ROLE_ADMIN, et laisse `validated` à false, ce que
 * UserChecker refuse à la connexion. Sans cette commande, aucun compte ne peut
 * ouvrir l'espace d'administration sur une base neuve.
 */
#[AsCommand(
    name: 'app:user:create-admin',
    description: 'Create or promote a validated user with ROLE_ADMIN (bootstrap de l’espace admin).'
)]
class CreateAdminUserCommand extends Command
{
    public function __construct(
        private UserService $userService,
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email du compte')
            ->addArgument('password', InputArgument::OPTIONAL, 'Mot de passe (obligatoire à la création, réinitialise le mot de passe si le compte existe)')
            ->addArgument('displayName', InputArgument::OPTIONAL, 'Nom affiché')
            ->addOption('backoffice', null, InputOption::VALUE_NONE, 'Accorde aussi ROLE_BACKOFFICE (plans de salle, événements, reporting)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email       = (string) $input->getArgument('email');
        $password    = $input->getArgument('password');
        $displayName = $input->getArgument('displayName');

        $user    = $this->userRepository->findByEmail($email);
        $created = false;

        if (!$user) {
            if (!is_string($password) || $password === '') {
                $io->error('Le mot de passe est obligatoire pour créer un compte.');

                return Command::INVALID;
            }

            try {
                $user = $this->userService->register($email, $password);
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());

                return Command::INVALID;
            }

            $created = true;
        } elseif (is_string($password) && $password !== '') {
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
            $io->note('Compte existant : mot de passe réinitialisé.');
        }

        if (is_string($displayName) && $displayName !== '') {
            $user->setDisplayName($displayName);
        }

        $roles = $user->getRoles();
        foreach (['ROLE_USER', 'ROLE_ADMIN'] as $role) {
            if (!in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }
        if ($input->getOption('backoffice') && !in_array('ROLE_BACKOFFICE', $roles, true)) {
            $roles[] = 'ROLE_BACKOFFICE';
        }
        $user->setRoles(array_values(array_unique($roles)));

        // Sans ces deux drapeaux, UserChecker rejette la connexion.
        if (!$user->isValidated()) {
            $user->setValidated(true);
            $io->note('Compte validé (sans quoi la connexion est refusée).');
        }
        if (!$user->isActive()) {
            $user->setActive(true);
            $io->note('Compte réactivé.');
        }

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf(
            '%s %s — rôles : %s',
            $email,
            $created ? 'créé' : 'promu',
            implode(', ', $user->getRoles())
        ));

        return Command::SUCCESS;
    }
}
