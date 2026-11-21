<?php

namespace App\Entity;

use App\Repository\GiftCardRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// Carte cadeau : achetée en ligne (paiement Stripe), code envoyé par e-mail, utilisable sur les articles d'une commande.
#[ORM\Entity(repositoryClass: GiftCardRepository::class)]
class GiftCard
{
    public const PENDING = 'pending';     // paiement pas encore confirmé
    public const ACTIVE = 'active';
    public const CANCELLED = 'cancelled';

    // Validité : un an à partir du paiement
    public const VALIDITY = '+1 year';
    public const MIN_CENTS = 1000;
    public const MAX_CENTS = 50000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 24, unique: true)]
    private string $code = '';

    #[ORM\Column]
    private int $initialCents = 0;

    #[ORM\Column]
    private int $balanceCents = 0;

    #[ORM\Column(length: 12)]
    private string $status = self::PENDING;

    #[ORM\Column(length: 120)]
    private string $buyerName = '';

    #[ORM\Column(length: 180)]
    private string $buyerEmail = '';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $recipientName = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $recipientEmail = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(length: 2)]
    private string $lang = 'fr';

    #[ORM\Column(length: 255, nullable: true, unique: true)]
    private ?string $stripeSessionId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $c): static { $this->code = $c; return $this; }
    public function getInitialCents(): int { return $this->initialCents; }
    public function setInitialCents(int $c): static { $this->initialCents = $c; $this->balanceCents = $c; return $this; }
    public function getBalanceCents(): int { return $this->balanceCents; }
    public function setBalanceCents(int $c): static { $this->balanceCents = max(0, $c); return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }
    public function getBuyerName(): string { return $this->buyerName; }
    public function getBuyerEmail(): string { return $this->buyerEmail; }
    public function setBuyer(string $name, string $email): static { $this->buyerName = $name; $this->buyerEmail = $email; return $this; }
    public function getRecipientName(): ?string { return $this->recipientName; }
    public function getRecipientEmail(): ?string { return $this->recipientEmail; }
    public function setRecipient(?string $name, ?string $email): static { $this->recipientName = $name; $this->recipientEmail = $email; return $this; }
    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $m): static { $this->message = $m; return $this; }
    public function getLang(): string { return $this->lang; }
    public function setLang(string $l): static { $this->lang = $l === 'en' ? 'en' : 'fr'; return $this; }
    public function getStripeSessionId(): ?string { return $this->stripeSessionId; }
    public function setStripeSessionId(?string $s): static { $this->stripeSessionId = $s; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function setPaidAt(?\DateTimeImmutable $d): static { $this->paidAt = $d; return $this; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeImmutable $d): static { $this->expiresAt = $d; return $this; }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $now > $this->expiresAt;
    }

    /** Raison pour laquelle la carte ne peut pas servir maintenant, ou null (fr). */
    public function problem(\DateTimeImmutable $now): ?string
    {
        if ($this->status !== self::ACTIVE) {
            return 'Cette carte cadeau n’est pas utilisable.';
        }
        if ($this->isExpired($now)) {
            return 'Cette carte cadeau a expiré.';
        }
        if ($this->balanceCents <= 0) {
            return 'Le solde de cette carte cadeau est épuisé.';
        }

        return null;
    }

    /** Code lisible à partir de 12 caractères aléatoires sans ambiguïté : GDA-XXXX-XXXX-XXXX */
    public static function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $chars = '';
        for ($i = 0; $i < 12; ++$i) {
            $chars .= $alphabet[random_int(0, \strlen($alphabet) - 1)];
        }

        return 'GDA-'.implode('-', str_split($chars, 4));
    }

    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $code));
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status,
            'initial' => Money::fromCents($this->initialCents),
            'balance' => Money::fromCents($this->balanceCents),
            'buyer' => ['name' => $this->buyerName, 'email' => $this->buyerEmail],
            'recipient' => ['name' => $this->recipientName, 'email' => $this->recipientEmail],
            'message' => $this->message,
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
            'paidAt' => $this->paidAt?->format(\DATE_ATOM),
            'expiresAt' => $this->expiresAt?->format(\DATE_ATOM),
        ];
    }
}
