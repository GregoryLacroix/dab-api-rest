<?php

namespace App\Repository;

use App\Entity\StockAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StockAlert> */
class StockAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockAlert::class);
    }

    /** @return array<int, array{productId: int, count: int}> produits ayant des inscrits, du plus demandé au moins demandé */
    public function countsByProduct(): array
    {
        $rows = $this->createQueryBuilder('a')->select('a.productId AS productId, COUNT(a.id) AS n')
            ->groupBy('a.productId')->orderBy('n', 'DESC')->getQuery()->getArrayResult();

        return array_map(static fn (array $r) => ['productId' => (int) $r['productId'], 'count' => (int) $r['n']], $rows);
    }

    /** Retire une alerte de façon atomique : seul celui qui la retire envoie l'e-mail (pas de doublon). */
    public function claim(StockAlert $a): bool
    {
        return 1 === $this->createQueryBuilder('a')->delete()->where('a.id = :id')->setParameter('id', $a->getId())->getQuery()->execute();
    }
}
