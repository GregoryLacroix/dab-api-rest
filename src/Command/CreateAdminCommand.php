<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:create-admin', description: 'Crée (ou met à jour) un compte administrateur déjà confirmé')]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Adresse e-mail de l’administrateur')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nom affiché', 'Administrateur')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Mot de passe (sinon il vous est demandé, saisie masquée)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Adresse e-mail invalide.');

            return Command::FAILURE;
        }
        $password = $input->getOption('password') ?? $io->askHidden('Mot de passe (8 caractères minimum)');
        if (!is_string($password) || strlen($password) < 8) {
            $io->error('Le mot de passe doit contenir 8 caractères minimum.');

            return Command::FAILURE;
        }

        $user = $this->users->findOneByEmail($email) ?? new User();
        $user->setEmail($email)->setName((string) $input->getOption('name'))->setRoles(['ROLE_ADMIN'])->setVerified(true);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        $io->success("Administrateur « {$user->getEmail()} » prêt.");

        return Command::SUCCESS;
    }
}
