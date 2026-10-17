<?php

namespace App\Repository;

use App\Entity\ContactMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ContactMessage> */
class ContactMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactMessage::class);
    }

    /** @return ContactMessage[] */
    public function findByEmail(string $email): array
    {
        return $this->createQueryBuilder('m')->where('LOWER(m.email) = :e')->setParameter('e', mb_strtolower($email))
            ->orderBy('m.id', 'DESC')->getQuery()->getResult();
    }

    public function deleteByEmail(string $email): void
    {
        $this->createQueryBuilder('m')->delete()->where('LOWER(m.email) = :e')->setParameter('e', mb_strtolower($email))->getQuery()->execute();
    }

    // Les plus récents d'abord ; ?read=0 pour les non lus ; ?q= cherche dans nom, e-mail et texte
    public function search(?bool $read, ?string $q): QueryBuilder
    {
        $qb = $this->createQueryBuilder('m')->orderBy('m.id', 'DESC');
        if ($read !== null) {
            $qb->andWhere('m.isRead = :read')->setParameter('read', $read);
        }
        if ($q !== null && trim($q) !== '') {
            $qb->andWhere('LOWER(m.name) LIKE :q OR LOWER(m.email) LIKE :q OR LOWER(m.message) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower(trim($q)).'%');
        }

        return $qb;
    }
}
