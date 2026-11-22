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

    /**
     * @param array{sort?: ?string, available?: bool} $options tri : new | price_asc | price_desc (défaut : plus ancien d'abord) ; available : uniquement les pièces en stock
     */
    public function search(?string $categoryId, ?string $q, ?bool $published, array $options = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p');
        match ($options['sort'] ?? null) {
            'new' => $qb->orderBy('p.id', 'DESC'),
            'price_asc' => $qb->orderBy('p.priceCents', 'ASC')->addOrderBy('p.id', 'ASC'),
            'price_desc' => $qb->orderBy('p.priceCents', 'DESC')->addOrderBy('p.id', 'ASC'),
            default => $qb->orderBy('p.id', 'ASC'),
        };
        if (!empty($options['available'])) {
            $qb->andWhere('p.stock > 0');
        }
        if ($categoryId !== null && $categoryId !== '') {
            $qb->andWhere('IDENTITY(p.category) = :cat')->setParameter('cat', $categoryId);
        }
        if ($q !== null && trim($q) !== '') {
            // recherche dans le nom (FR/EN) et la description ; % et _ saisis par le visiteur sont neutralisés
            $like = '%'.addcslashes(mb_strtolower(trim($q)), '%_\\').'%';
            $qb->andWhere('(LOWER(p.name) LIKE :q OR LOWER(p.nameEn) LIKE :q OR LOWER(p.description) LIKE :q OR LOWER(p.descriptionEn) LIKE :q)')->setParameter('q', $like);
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
