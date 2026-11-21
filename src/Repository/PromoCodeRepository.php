<?php

namespace App\Repository;

use App\Entity\PromoCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PromoCode> */
class PromoCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PromoCode::class);
    }

    public function findByCode(string $code): ?PromoCode
    {
        return $this->findOneBy(['code' => PromoCode::normalize($code)]);
    }

    public function search(?string $q): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')->orderBy('p.id', 'DESC');
        if ($q !== null && trim($q) !== '') {
            $qb->andWhere('p.code LIKE :q')->setParameter('q', '%'.strtoupper(trim($q)).'%');
        }

        return $qb;
    }

    /** Prend une utilisation de façon atomique (false si le plafond est atteint entre-temps). */
    public function consume(PromoCode $promo): bool
    {
        return 1 === $this->createQueryBuilder('p')->update()
            ->set('p.usedCount', 'p.usedCount + 1')
            ->where('p.id = :id')->andWhere('p.maxUses IS NULL OR p.usedCount < p.maxUses')
            ->setParameter('id', $promo->getId())->getQuery()->execute();
    }

    public function restore(string $code): void
    {
        $this->createQueryBuilder('p')->update()
            ->set('p.usedCount', 'p.usedCount - 1')
            ->where('p.code = :c')->andWhere('p.usedCount > 0')
            ->setParameter('c', $code)->getQuery()->execute();
    }
}
