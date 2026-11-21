<?php

namespace App\Repository;

use App\Entity\GiftCard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GiftCard> */
class GiftCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GiftCard::class);
    }

    public function findByCode(string $code): ?GiftCard
    {
        return $this->findOneBy(['code' => GiftCard::normalize($code)]);
    }

    /** Les cartes en attente de paiement n'apparaissent pas dans le back-office. */
    public function search(?string $q): QueryBuilder
    {
        $qb = $this->createQueryBuilder('g')->where('g.status <> :p')->setParameter('p', GiftCard::PENDING)->orderBy('g.id', 'DESC');
        if ($q !== null && trim($q) !== '') {
            $qb->andWhere('g.code LIKE :q OR g.buyerEmail LIKE :q2 OR g.recipientEmail LIKE :q2')
                ->setParameter('q', '%'.strtoupper(trim($q)).'%')->setParameter('q2', '%'.trim($q).'%');
        }

        return $qb;
    }

    /** Retire `$cents` du solde SI le solde suffit (atomique : deux commandes ne peuvent pas dépenser deux fois le même solde). */
    public function debit(GiftCard $card, int $cents): bool
    {
        return 1 === $this->createQueryBuilder('g')->update()
            ->set('g.balanceCents', 'g.balanceCents - :c')
            ->where('g.id = :id')->andWhere('g.balanceCents >= :c')->andWhere('g.status = :a')
            ->setParameter('c', $cents)->setParameter('id', $card->getId())->setParameter('a', GiftCard::ACTIVE)->getQuery()->execute();
    }

    /** Rend `$cents` au solde (commande annulée ou remboursée), sans dépasser le montant initial. */
    public function credit(string $code, int $cents): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE gift_card SET balance_cents = LEAST(initial_cents, balance_cents + :c) WHERE code = :code',
            ['c' => $cents, 'code' => $code],
        );
    }
}
