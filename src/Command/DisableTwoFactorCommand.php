<?php

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\TwoFactorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// Dépannage : un administrateur a perdu son téléphone ET ses codes de secours. À lancer sur le serveur (accès SSH) :
//   php bin/console app:disable-2fa adresse@exemple.fr
#[AsCommand(name: 'app:disable-2fa', description: 'Désactive la double authentification d’un compte (téléphone et codes de secours perdus)')]
final class DisableTwoFactorCommand extends Command
{
    public function __construct(private readonly UserRepository $users, private readonly TwoFactorService $twoFactor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Adresse e-mail du compte');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = $this->users->findOneByEmail(mb_strtolower(trim((string) $input->getArgument('email'))));
        if ($user === null) {
            $io->error('Aucun compte avec cette adresse.');

            return Command::FAILURE;
        }
        if (!$this->twoFactor->isEnabled($user)) {
            $io->note('La double authentification n’est pas activée sur ce compte.');

            return Command::SUCCESS;
        }
        $this->twoFactor->disable($user);
        $io->success('Double authentification désactivée pour '.$user->getEmail().'. La personne peut la réactiver depuis « Mon compte ».');

        return Command::SUCCESS;
    }
}
