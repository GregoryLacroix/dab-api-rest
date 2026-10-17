<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\OrderStatus;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Order> */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    // Recherche par n° (CMD-1002 ou 1002), nom ou e-mail ; les plus récentes d'abord (comme le store React)
    public function search(?OrderStatus $status, ?string $q): QueryBuilder
    {
        $qb = $this->createQueryBuilder('o')->orderBy('o.id', 'DESC');
        if ($status !== null) {
            $qb->andWhere('o.status = :status')->setParameter('status', $status->value);
        }
        $q = $q === null ? '' : trim($q);
        if ($q !== '') {
            $or = $qb->expr()->orX('LOWER(o.customerName) LIKE :q', 'LOWER(o.customerEmail) LIKE :q');
            $qb->setParameter('q', '%'.mb_strtolower($q).'%');
            if (preg_match('/^(?:cmd-)?(\d+)$/i', $q, $m)) {
                $or->add('o.id = :ref');
                $qb->setParameter('ref', (int) $m[1] - Order::REF_OFFSET);
            }
            $qb->andWhere($or);
        }

        return $qb;
    }

    /** Commandes rattachées au compte, ou passées avec la même adresse e-mail (export RGPD) */
    public function findForPerson(User $user): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.user = :u OR LOWER(o.customerEmail) = :e')
            ->setParameter('u', $user)->setParameter('e', mb_strtolower($user->getEmail()))
            ->orderBy('o.id', 'DESC')->getQuery()->getResult();
    }

    /** Suppression de compte : les commandes sont conservées (obligation comptable) mais détachées du compte */
    public function detachUser(User $user): void
    {
        $this->createQueryBuilder('o')->update()->set('o.user', 'NULL')
            ->where('o.user = :u')->setParameter('u', $user)->getQuery()->execute();
    }

    /** Chiffre d'affaires : hors annulées et hors commandes en ligne pas encore payées */
    public function revenueCents(): int
    {
        return (int) $this->createQueryBuilder('o')->select('COALESCE(SUM(o.totalCents), 0)')
            ->where('o.status != :c')
            ->andWhere('NOT (o.status = :p AND o.stripeSessionId IS NOT NULL)')
            ->setParameter('c', OrderStatus::Cancelled->value)->setParameter('p', OrderStatus::Pending->value)
            ->getQuery()->getSingleScalarResult();
    }

    /** Commandes à traiter : payées, et celles enregistrées sans paiement en ligne (« en attente » sans session Stripe) */
    public function countToHandle(): int
    {
        return (int) $this->createQueryBuilder('o')->select('COUNT(o.id)')
            ->where('o.status = :paid OR (o.status = :pending AND o.stripeSessionId IS NULL)')
            ->setParameter('paid', OrderStatus::Paid->value)->setParameter('pending', OrderStatus::Pending->value)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Change le statut SEULEMENT si la commande est encore dans l'état $from (un seul gagnant si le webhook et la page de retour
     * arrivent en même temps : l'e-mail de confirmation n'est envoyé qu'une fois). Renvoie true si c'est nous qui l'avons changé.
     */
    public function transition(Order $order, OrderStatus $from, OrderStatus $to): bool
    {
        $changed = $this->createQueryBuilder('o')->update()
            ->set('o.status', ':to')
            ->where('o.id = :id')->andWhere('o.status = :from')
            ->setParameter('to', $to->value)->setParameter('from', $from->value)->setParameter('id', $order->getId())
            ->getQuery()->execute();

        return $changed === 1;
    }

    /** Commandes en ligne restées « en attente » après l'expiration de la session Stripe (webhook manqué) */
    public function findStalePending(\DateTimeImmutable $createdBefore): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.status = :p')->andWhere('o.stripeSessionId IS NOT NULL')->andWhere('o.createdAt < :before')
            ->setParameter('p', OrderStatus::Pending->value)->setParameter('before', $createdBefore, Types::DATETIME_IMMUTABLE)
            ->getQuery()->getResult();
    }

    /** @param OrderStatus[] $statuses */
    public function countByStatuses(array $statuses): int
    {
        return (int) $this->createQueryBuilder('o')->select('COUNT(o.id)')
            ->where('o.status IN (:s)')->setParameter('s', array_map(fn (OrderStatus $s) => $s->value, $statuses))
            ->getQuery()->getSingleScalarResult();
    }
}
