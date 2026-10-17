<?php

namespace App\Repository;

use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ApiToken> */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    public function findValid(string $hash): ?ApiToken
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.tokenHash = :h')->setParameter('h', $hash)
            ->andWhere('t.expiresAt > :now')->setParameter('now', new \DateTimeImmutable())
            ->getQuery()->getOneOrNullResult();
    }

    public function deleteForUser(User $user): void
    {
        $this->createQueryBuilder('t')->delete()->where('t.user = :u')->setParameter('u', $user)->getQuery()->execute();
    }

    public function purgeExpired(): void
    {
        $this->createQueryBuilder('t')->delete()
            ->where('t.expiresAt < :now')->setParameter('now', new \DateTimeImmutable())
            ->getQuery()->execute();
    }
}
