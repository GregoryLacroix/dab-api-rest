<?php

namespace App\Service;

use App\Entity\Order;
use App\Entity\Product;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

// Stock des pièces uniques. Une commande RÉSERVE les pièces dès sa création (même « en attente de paiement » : la page de
// paiement dure 31 minutes) ; la réservation est rendue si le paiement échoue, expire ou si la commande est annulée avant envoi.
// Le décompte est fait par une requête atomique (« retirer N SI stock >= N ») : deux clients ne peuvent jamais acheter la même pièce.
final class StockService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrderRepository $orders,
    ) {
    }

    /**
     * À appeler DANS la transaction de création de la commande : retire les quantités, ou lève une 409 si une pièce manque.
     *
     * @param array<int, int> $qtyById produit => quantité
     */
    public function reserve(array $qtyById, string $locale = 'fr'): void
    {
        ksort($qtyById); // toujours dans le même ordre : pas de blocage croisé entre deux commandes simultanées
        foreach ($qtyById as $id => $qty) {
            $done = $this->em->createQueryBuilder()->update(Product::class, 'p')
                ->set('p.stock', 'p.stock - :qty')
                ->where('p.id = :id')->andWhere('p.stock >= :qty')
                ->setParameter('qty', $qty)->setParameter('id', $id)
                ->getQuery()->execute();
            if ($done !== 1) {
                $name = $this->em->getRepository(Product::class)->find($id)?->getName() ?? '#'.$id;
                throw new ConflictHttpException($locale === 'en'
                    ? sprintf('Sorry, “%s” is no longer available in the requested quantity. It may have just been sold.', $name)
                    : sprintf('Désolé, « %s » n’est plus disponible dans la quantité demandée : la pièce vient peut-être d’être vendue.', $name));
            }
        }
    }

    /** Remet en vente les pièces d'une commande (idempotent : sans effet si déjà fait). */
    public function release(Order $order): void
    {
        if (!$this->orders->claimStockRelease($order)) {
            return;
        }
        foreach ($order->getItems() as $item) {
            $this->em->createQueryBuilder()->update(Product::class, 'p')
                ->set('p.stock', 'p.stock + :qty')
                ->where('p.id = :id')
                ->setParameter('qty', (int) $item['qty'])->setParameter('id', (int) $item['productId'])
                ->getQuery()->execute();
        }
    }
}
