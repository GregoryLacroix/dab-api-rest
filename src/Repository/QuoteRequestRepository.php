<?php

namespace App\Repository;

use App\Entity\QuoteRequest;
use App\Entity\QuoteStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<QuoteRequest> */
class QuoteRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuoteRequest::class);
    }

    // Les plus récentes d'abord ; ?status=… ; ?q= cherche dans nom, e-mail, lieu et description
    public function search(?string $status, ?string $q): QueryBuilder
    {
        $qb = $this->createQueryBuilder('r')->orderBy('r.id', 'DESC');
        if ($status !== null && $status !== '' && QuoteStatus::tryFrom($status) !== null) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }
        if ($q !== null && trim($q) !== '') {
            $qb->andWhere('LOWER(r.name) LIKE :q OR LOWER(r.email) LIKE :q OR LOWER(r.location) LIKE :q OR LOWER(r.description) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower(trim($q)).'%');
        }

        return $qb;
    }

    /** @return QuoteRequest[] */
    public function findByEmail(string $email): array
    {
        return $this->createQueryBuilder('r')->where('LOWER(r.email) = :e')->setParameter('e', mb_strtolower($email))
            ->orderBy('r.id', 'DESC')->getQuery()->getResult();
    }

    /** Demandes anciennes sans acompte (purge RGPD) */
    public function findOlderThanWithoutDeposit(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('r')->where('r.createdAt < :before')->andWhere('r.depositPaidAt IS NULL')
            ->setParameter('before', $before, Types::DATETIME_IMMUTABLE)->getQuery()->getResult();
    }

    /** Marque l'acompte comme réglé SEULEMENT s'il ne l'était pas (un seul gagnant si webhook et page de retour se croisent). */
    public function claimDepositPaid(QuoteRequest $quote): bool
    {
        $changed = $this->createQueryBuilder('r')->update()
            ->set('r.depositPaidAt', ':now')->set('r.status', ':status')
            ->where('r.id = :id')->andWhere('r.depositPaidAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')), Types::DATETIME_IMMUTABLE)
            ->setParameter('status', QuoteStatus::DepositPaid->value)->setParameter('id', $quote->getId())
            ->getQuery()->execute();

        return $changed === 1;
    }
}
