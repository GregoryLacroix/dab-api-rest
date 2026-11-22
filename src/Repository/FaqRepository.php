<?php

namespace App\Repository;

use App\Entity\Faq;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Faq> */
class FaqRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Faq::class);
    }

    public function listQuery(bool $publishedOnly): QueryBuilder
    {
        $qb = $this->createQueryBuilder('f')->orderBy('f.position', 'ASC')->addOrderBy('f.id', 'ASC');
        if ($publishedOnly) {
            $qb->where('f.published = true');
        }

        return $qb;
    }
}
