<?php

namespace App\Entity;

use App\Repository\PromoCodeRepository;
use Doctrine\ORM\Mapping as ORM;

// Code promo : remise en pourcentage ou en montant fixe sur le total des articles (hors livraison).
#[ORM\Entity(repositoryClass: PromoCodeRepository::class)]
class PromoCode
{
    public const PERCENT = 'percent';
    public const FIXED = 'fixed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Toujours en majuscules, sans espaces (ex. BIENVENUE10)
    #[ORM\Column(length: 40, unique: true)]
    private string $code = '';

    #[ORM\Column(length: 10)]
    private string $kind = self::PERCENT;

    // Pourcentage (1 à 100) ou montant en centimes
    #[ORM\Column]
    private int $value = 10;

    // Montant minimum d'articles (centimes) pour que le code s'applique ; 0 = aucun minimum
    #[ORM\Column]
    private int $minOrderCents = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    // Nombre maximal d'utilisations (null = illimité)
    #[ORM\Column(nullable: true)]
    private ?int $maxUses = null;

    #[ORM\Column]
    private int $usedCount = 0;

    // Un seul usage par adresse e-mail
    #[ORM\Column]
    private bool $onePerCustomer = false;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $c): static { $this->code = self::normalize($c); return $this; }
    public function getKind(): string { return $this->kind; }
    public function setKind(string $k): static { $this->kind = $k === self::FIXED ? self::FIXED : self::PERCENT; return $this; }
    public function getValue(): int { return $this->value; }
    public function setValue(int $v): static { $this->value = $v; return $this; }
    public function getMinOrderCents(): int { return $this->minOrderCents; }
    public function setMinOrderCents(int $c): static { $this->minOrderCents = max(0, $c); return $this; }
    public function getStartsAt(): ?\DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(?\DateTimeImmutable $d): static { $this->startsAt = $d; return $this; }
    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(?\DateTimeImmutable $d): static { $this->endsAt = $d; return $this; }
    public function getMaxUses(): ?int { return $this->maxUses; }
    public function setMaxUses(?int $n): static { $this->maxUses = $n; return $this; }
    public function getUsedCount(): int { return $this->usedCount; }
    public function isOnePerCustomer(): bool { return $this->onePerCustomer; }
    public function setOnePerCustomer(bool $b): static { $this->onePerCustomer = $b; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $b): static { $this->active = $b; return $this; }

    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $code));
    }

    /** Remise en centimes pour un total d'articles donné (sans plafond lié au paiement). */
    public function discountFor(int $itemsCents): int
    {
        $d = $this->kind === self::PERCENT ? intdiv($itemsCents * $this->value, 100) : $this->value;

        return max(0, min($d, $itemsCents));
    }

    /** Raison pour laquelle le code ne peut pas être utilisé maintenant, ou null s'il est valide (fr). */
    public function problem(int $itemsCents, \DateTimeImmutable $now): ?string
    {
        if (!$this->active) {
            return 'Ce code n’est plus valable.';
        }
        if ($this->startsAt !== null && $now < $this->startsAt) {
            return 'Ce code n’est pas encore valable.';
        }
        if ($this->endsAt !== null && $now > $this->endsAt) {
            return 'Ce code a expiré.';
        }
        if ($this->maxUses !== null && $this->usedCount >= $this->maxUses) {
            return 'Ce code a atteint son nombre maximal d’utilisations.';
        }
        if ($itemsCents < $this->minOrderCents) {
            return 'Ce code s’applique à partir de '.number_format($this->minOrderCents / 100, 2, ',', ' ').' € d’achats.';
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'kind' => $this->kind,
            'value' => $this->kind === self::PERCENT ? $this->value : Money::fromCents($this->value),
            'minOrder' => Money::fromCents($this->minOrderCents),
            'startsAt' => $this->startsAt?->format(\DATE_ATOM),
            'endsAt' => $this->endsAt?->format(\DATE_ATOM),
            'maxUses' => $this->maxUses,
            'usedCount' => $this->usedCount,
            'onePerCustomer' => $this->onePerCustomer,
            'active' => $this->active,
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
        ];
    }
}
