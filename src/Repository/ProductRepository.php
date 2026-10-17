<?php

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Product> */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    public function search(?string $categoryId, ?string $q, ?bool $published): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')->orderBy('p.id', 'ASC');
        if ($categoryId !== null && $categoryId !== '') {
            $qb->andWhere('IDENTITY(p.category) = :cat')->setParameter('cat', $categoryId);
        }
        if ($q !== null && trim($q) !== '') {
            $qb->andWhere('LOWER(p.name) LIKE :q')->setParameter('q', '%'.mb_strtolower(trim($q)).'%');
        }
        if ($published !== null) {
            $qb->andWhere('p.published = :pub')->setParameter('pub', $published);
        }

        return $qb;
    }

    /** @return Product[] */
    public function findPublished(): array
    {
        return $this->search(null, null, true)->getQuery()->getResult();
    }

    /**
     * @param int[] $ids
     * @return array<int, Product> indexés par identifiant
     */
    public function findPublishedByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $found = $this->createQueryBuilder('p')
            ->where('p.id IN (:ids)')->setParameter('ids', $ids)
            ->andWhere('p.published = true')
            ->getQuery()->getResult();
        $byId = [];
        foreach ($found as $product) {
            $byId[$product->getId()] = $product;
        }

        return $byId;
    }
}
