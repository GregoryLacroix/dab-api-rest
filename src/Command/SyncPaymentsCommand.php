<?php

namespace App\Command;

use App\Repository\OrderRepository;
use App\Service\PaymentService;
use App\Service\StripeGateway;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// Filet de sécurité si un webhook Stripe a été manqué : interroge Stripe pour les commandes restées « en attente » une fois
// la page de paiement expirée (31 min), les passe à « payée » ou « annulée ». À planifier, par exemple toutes les 15 minutes :
//   */15 * * * *  php /chemin/vers/dab-api/bin/console app:sync-payments
#[AsCommand(name: 'app:sync-payments', description: 'Rattrape les paiements Stripe dont le webhook n’est pas arrivé')]
final class SyncPaymentsCommand extends Command
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly PaymentService $payments,
        private readonly StripeGateway $stripe,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->stripe->isEnabled()) {
            $io->note('Paiement Stripe désactivé (STRIPE_SECRET_KEY est vide) : rien à faire.');

            return Command::SUCCESS;
        }

        $stale = $this->orders->findStalePending(new \DateTimeImmutable('-35 minutes', new \DateTimeZone('UTC')));
        $rows = [];
        foreach ($stale as $order) {
            $this->payments->sync($order);
            $rows[] = [$order->getReference(), $order->getStatus()->value];
        }
        if ($rows !== []) {
            $io->table(['Commande', 'Statut après vérification'], $rows);
        }
        $io->success(\count($rows).' commande(s) vérifiée(s).');

        return Command::SUCCESS;
    }
}
