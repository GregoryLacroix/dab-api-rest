<?php

namespace App\Entity;

use App\Repository\ReviewRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// Avis d'un client sur un produit : un seul par personne et par produit, réservé aux acheteurs, publié après modération
#[ORM\Entity(repositoryClass: ReviewRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_review_user_product', columns: ['user_id', 'product_id'])]
class Review
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    // Compte supprimé -> l'avis est supprimé avec lui (voir AccountController)
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    // Prénom + initiale du nom (« Marie D. ») : seul élément d'identité affiché publiquement
    #[ORM\Column(length: 60)]
    private string $authorName = '';

    #[ORM\Column(type: Types::SMALLINT)]
    private int $rating = 5;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $body = '';

    #[ORM\Column(length: 20, enumType: ReviewStatus::class)]
    private ReviewStatus $status = ReviewStatus::Pending;

    // Réponse publique de la boutique (facultative)
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reply = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProduct(): Product { return $this->product; }
    public function setProduct(Product $p): static { $this->product = $p; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $u): static { $this->user = $u; return $this; }
    public function getStatus(): ReviewStatus { return $this->status; }
    public function setStatus(ReviewStatus $s): static { $this->status = $s; return $this; }
    public function getReply(): ?string { return $this->reply; }
    public function setReply(?string $r): static { $this->reply = $r === null || trim($r) === '' ? null : trim($r); return $this; }
    public function getRating(): int { return $this->rating; }
    public function getBody(): string { return $this->body; }
    public function getTitle(): ?string { return $this->title; }
    public function getAuthorName(): string { return $this->authorName; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function setContent(string $authorName, int $rating, ?string $title, string $body): static
    {
        $this->authorName = $authorName;
        $this->rating = $rating;
        $this->title = $title;
        $this->body = $body;

        return $this;
    }

    /** « Marie Dupont » -> « Marie D. » ; un seul mot -> tel quel */
    public static function displayName(string $fullName): string
    {
        $parts = preg_split('/\s+/u', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return 'Client';
        }
        $name = mb_substr($parts[0], 0, 40);

        return count($parts) > 1 ? $name.' '.mb_strtoupper(mb_substr(end($parts), 0, 1)).'.' : $name;
    }

    /** Forme publique (sans identité du compte) */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'author' => $this->authorName,
            'rating' => $this->rating,
            'title' => $this->title,
            'body' => $this->body,
            'reply' => $this->reply,
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
        ];
    }

    /** Forme complète (back-office et « mon avis ») */
    public function toArray(): array
    {
        return $this->toPublicArray() + [
            'status' => $this->status->value,
            'productId' => $this->product->getId(),
            'productName' => $this->product->getName(),
            'email' => $this->user?->getEmail(),
        ];
    }
}
