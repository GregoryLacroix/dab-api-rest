<?php

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Category> */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    public function ordered(): QueryBuilder
    {
        return $this->createQueryBuilder('c')->orderBy('c.position', 'ASC')->addOrderBy('c.id', 'ASC');
    }

    public function nextPosition(): int
    {
        return 1 + (int) $this->createQueryBuilder('c')->select('MAX(c.position)')->getQuery()->getSingleScalarResult();
    }

    // Comparaison insensible à la casse (comme dans le back-office React)
    public function nameExists(string $name, ?string $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('c')->select('COUNT(c.id)')
            ->where('LOWER(c.name) = :n')->setParameter('n', mb_strtolower($name));
        if ($exceptId !== null) {
            $qb->andWhere('c.id != :id')->setParameter('id', $exceptId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
