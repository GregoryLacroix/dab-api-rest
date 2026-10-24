<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email = '';

    #[ORM\Column(length: 120)]
    private string $name = '';

    /** @var string[] */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column]
    private bool $verified = false;

    // Seul le hash (SHA-256) du jeton de confirmation est conservé en base
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $verificationHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verificationExpiresAt = null;

    // Réinitialisation du mot de passe : seul le hash (SHA-256) du jeton est conservé, valable 1 heure
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $resetHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resetExpiresAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): static { $this->email = mb_strtolower(trim($email)); return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = trim($name); return $this; }
    public function getUserIdentifier(): string { return $this->email; }

    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /** @param string[] $roles */
    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }
    public function isAdmin(): bool { return in_array('ROLE_ADMIN', $this->roles, true); }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): static { $this->password = $password; return $this; }
    /** Faux pour un compte créé via Google/Facebook (mot de passe vide : aucune connexion par mot de passe possible). */
    public function hasPassword(): bool { return $this->password !== ''; }
    public function eraseCredentials(): void {}

    public function isVerified(): bool { return $this->verified; }
    public function setVerified(bool $verified): static { $this->verified = $verified; return $this; }
    public function getResetHash(): ?string { return $this->resetHash; }
    public function setResetHash(?string $hash): static { $this->resetHash = $hash; return $this; }
    public function getResetExpiresAt(): ?\DateTimeImmutable { return $this->resetExpiresAt; }
    public function setResetExpiresAt(?\DateTimeImmutable $at): static { $this->resetExpiresAt = $at; return $this; }
    public function getVerificationHash(): ?string { return $this->verificationHash; }
    public function setVerificationHash(?string $hash): static { $this->verificationHash = $hash; return $this; }
    public function getVerificationExpiresAt(): ?\DateTimeImmutable { return $this->verificationExpiresAt; }
    public function setVerificationExpiresAt(?\DateTimeImmutable $at): static { $this->verificationExpiresAt = $at; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** Forme exposée au React */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->getRoles(),
            'isAdmin' => $this->isAdmin(),
            'verified' => $this->verified,
            'hasPassword' => $this->hasPassword(),
        ];
    }
}
