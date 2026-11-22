<?php

namespace App\Repository;

use App\Entity\Product;
use App\Entity\Review;
use App\Entity\ReviewStatus;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Review> */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /** Avis publiés d'un produit, les plus récents d'abord */
    public function approvedQuery(Product $product): QueryBuilder
    {
        return $this->createQueryBuilder('r')->where('r.product = :p')->andWhere('r.status = :s')
            ->setParameter('p', $product)->setParameter('s', ReviewStatus::Approved->value)->orderBy('r.id', 'DESC');
    }

    /** @return array{average: float|null, count: int, distribution: array<int, int>} note moyenne et répartition 5 -> 1 étoiles (avis publiés) */
    public function summary(Product $product): array
    {
        $rows = $this->createQueryBuilder('r')->select('r.rating AS rating, COUNT(r.id) AS n')
            ->where('r.product = :p')->andWhere('r.status = :s')
            ->setParameter('p', $product)->setParameter('s', ReviewStatus::Approved->value)
            ->groupBy('r.rating')->getQuery()->getArrayResult();
        $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $sum = 0;
        $count = 0;
        foreach ($rows as $row) {
            $dist[(int) $row['rating']] = (int) $row['n'];
            $sum += (int) $row['rating'] * (int) $row['n'];
            $count += (int) $row['n'];
        }

        return ['average' => $count > 0 ? round($sum / $count, 1) : null, 'count' => $count, 'distribution' => $dist];
    }

    /** Avis à mettre en avant sur l'accueil : publiés, 4 étoiles ou plus, les plus récents d'abord (produit encore en vente) */
    public function featured(int $limit): array
    {
        return $this->createQueryBuilder('r')->join('r.product', 'p')
            ->where('r.status = :s')->andWhere('r.rating >= 4')->andWhere('p.published = true')
            ->setParameter('s', ReviewStatus::Approved->value)->orderBy('r.id', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
    }

    /** @return array{average: float|null, count: int} note moyenne de tous les avis publiés */
    public function globalSummary(): array
    {
        $row = $this->createQueryBuilder('r')->select('COUNT(r.id) AS n, AVG(r.rating) AS avg')
            ->where('r.status = :s')->setParameter('s', ReviewStatus::Approved->value)->getQuery()->getSingleResult();
        $count = (int) $row['n'];

        return ['average' => $count > 0 ? round((float) $row['avg'], 1) : null, 'count' => $count];
    }

    public function findOneByUserAndProduct(User $user, Product $product): ?Review
    {
        return $this->findOneBy(['user' => $user, 'product' => $product]);
    }

    // Back-office : ?status=pending|approved|rejected &q=… (produit, auteur, texte) ; les plus récents d'abord
    public function search(?string $status, ?string $q): QueryBuilder
    {
        $qb = $this->createQueryBuilder('r')->join('r.product', 'p')->orderBy('r.id', 'DESC');
        if ($status !== null && ReviewStatus::tryFrom($status) !== null) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }
        if ($q !== null && trim($q) !== '') {
            $qb->andWhere('LOWER(p.name) LIKE :q OR LOWER(r.authorName) LIKE :q OR LOWER(r.body) LIKE :q OR LOWER(r.title) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower(trim($q)).'%');
        }

        return $qb;
    }

    public function countPending(): int
    {
        return $this->count(['status' => ReviewStatus::Pending]);
    }

    /** @return Review[] */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['id' => 'DESC']);
    }

    public function deleteForUser(User $user): void
    {
        $this->createQueryBuilder('r')->delete()->where('r.user = :u')->setParameter('u', $user)->getQuery()->execute();
    }
}
