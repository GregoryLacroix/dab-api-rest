<?php

namespace App\Repository;

use App\Entity\OAuthIdentity;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<OAuthIdentity> */
class OAuthIdentityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthIdentity::class);
    }

    /** @return string[] ex. ['google', 'facebook'] */
    public function providersFor(User $user): array
    {
        $rows = $this->createQueryBuilder('i')->select('i.provider')->where('i.user = :u')->setParameter('u', $user)
            ->getQuery()->getSingleColumnResult();

        return array_values(array_unique($rows));
    }

    /** Suppression de compte (RGPD) */
    public function deleteForUser(User $user): void
    {
        $this->createQueryBuilder('i')->delete()->where('i.user = :u')->setParameter('u', $user)->getQuery()->execute();
    }
}
