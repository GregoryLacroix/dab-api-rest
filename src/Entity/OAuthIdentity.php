<?php

namespace App\Entity;

use App\Repository\OAuthIdentityRepository;
use Doctrine\ORM\Mapping as ORM;

// Lien entre un compte du site et un compte chez un fournisseur (Google, Facebook) : « sub » / id stable du fournisseur
#[ORM\Entity(repositoryClass: OAuthIdentityRepository::class)]
#[ORM\Table(name: 'oauth_identity')]
#[ORM\UniqueConstraint(name: 'uniq_oauth_provider_subject', columns: ['provider', 'subject'])]
class OAuthIdentity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $provider;

    #[ORM\Column(length: 191)]
    private string $subject;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $provider, string $subject)
    {
        $this->user = $user;
        $this->provider = $provider;
        $this->subject = $subject;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getUser(): User { return $this->user; }
    public function getProvider(): string { return $this->provider; }
}
