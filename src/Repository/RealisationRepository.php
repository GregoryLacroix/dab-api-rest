<?php

namespace App\Repository;

use App\Entity\Realisation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Realisation> */
class RealisationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Realisation::class);
    }

    public function listQuery(bool $publishedOnly): QueryBuilder
    {
        $qb = $this->createQueryBuilder('r')->orderBy('r.position', 'ASC')->addOrderBy('r.completedAt', 'DESC')->addOrderBy('r.id', 'DESC');
        if ($publishedOnly) {
            $qb->where('r.published = true');
        }

        return $qb;
    }
}
