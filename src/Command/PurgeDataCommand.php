<?php

namespace App\Command;

use App\Entity\ContactMessage;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\QuoteRequestRepository;
use App\Service\QuoteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// Limitation de la durée de conservation (RGPD) : à planifier chaque jour, par exemple
//   0 3 * * *  php /chemin/vers/dab-api/bin/console app:purge-data
// Les valeurs par défaut correspondent à la politique de confidentialité du site.
#[AsCommand(name: 'app:purge-data', description: 'Supprime les données arrivées en fin de durée de conservation (RGPD)')]
final class PurgeDataCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ApiTokenRepository $tokens,
        private readonly QuoteRequestRepository $quotes,
        private readonly QuoteService $quoteService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('unverified-days', null, InputOption::VALUE_REQUIRED, 'Comptes dont l’e-mail n’est pas confirmé depuis plus de N jours', '30')
            ->addOption('messages-months', null, InputOption::VALUE_REQUIRED, 'Messages du formulaire de contact reçus il y a plus de N mois', '12')
            ->addOption('quotes-months', null, InputOption::VALUE_REQUIRED, 'Demandes de devis (sans acompte) reçues il y a plus de N mois', '36')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui serait supprimé sans rien supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dry = (bool) $input->getOption('dry-run');
        $accountsCut = new \DateTimeImmutable('-'.max(1, (int) $input->getOption('unverified-days')).' days');
        $messagesCut = new \DateTimeImmutable('-'.max(1, (int) $input->getOption('messages-months')).' months');

        // Demandes de devis sans acompte : supprimées avec leurs photos (un acompte réglé reste conservé : pièce comptable)
        $oldQuotes = $this->quotes->findOlderThanWithoutDeposit(new \DateTimeImmutable('-'.max(1, (int) $input->getOption('quotes-months')).' months'));

        // Comptes jamais confirmés (aucun jeton ni commande possible : ils n'ont jamais pu se connecter)
        $accounts = $this->em->createQueryBuilder()->from(User::class, 'u')
            ->where('u.verified = :no')->andWhere('u.createdAt < :cut')
            ->setParameter('no', false)->setParameter('cut', $accountsCut);
        $nAccounts = (int) (clone $accounts)->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();

        $messages = $this->em->createQueryBuilder()->from(ContactMessage::class, 'm')->where('m.createdAt < :cut')->setParameter('cut', $messagesCut);
        $nMessages = (int) (clone $messages)->select('COUNT(m.id)')->getQuery()->getSingleScalarResult();

        if (!$dry) {
            if ($nAccounts > 0) {
                $this->em->createQueryBuilder()->delete(User::class, 'u')->where('u.verified = :no')->andWhere('u.createdAt < :cut')
                    ->setParameter('no', false)->setParameter('cut', $accountsCut)->getQuery()->execute();
            }
            if ($nMessages > 0) {
                $this->em->createQueryBuilder()->delete(ContactMessage::class, 'm')->where('m.createdAt < :cut')
                    ->setParameter('cut', $messagesCut)->getQuery()->execute();
            }
            $this->quoteService->purge($oldQuotes);
            $this->tokens->purgeExpired();
        }

        $io->table(['Donnée', $dry ? 'À supprimer' : 'Supprimé'], [
            ['Comptes non confirmés (> '.$input->getOption('unverified-days').' jours)', $nAccounts],
            ['Messages de contact (> '.$input->getOption('messages-months').' mois)', $nMessages],
            ['Demandes de devis sans acompte (> '.$input->getOption('quotes-months').' mois)', count($oldQuotes)],
            ['Jetons de connexion expirés', $dry ? '—' : 'purgés'],
        ]);
        $io->success($dry ? 'Simulation terminée : rien n’a été supprimé.' : 'Purge terminée.');

        return Command::SUCCESS;
    }
}
