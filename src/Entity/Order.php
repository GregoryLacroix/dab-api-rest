<?php

namespace App\Entity;

use App\Repository\OrderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'shop_order')]
class Order
{
    // CMD-1001 = identifiant 1 + 1000, comme les commandes d'exemple du React
    public const REF_OFFSET = 1000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 20, enumType: OrderStatus::class)]
    private OrderStatus $status = OrderStatus::Pending;

    #[ORM\Column(length: 120)]
    private string $customerName = '';

    #[ORM\Column(length: 180)]
    private string $customerEmail = '';

    /** @var array<int, array{productId: int, name: string, price: int|float, qty: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $items = [];

    #[ORM\Column]
    private int $totalCents = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $user = null;

    // --- Paiement Stripe (Checkout). Tous nuls pour une commande enregistrée sans paiement en ligne.
    #[ORM\Column(length: 255, nullable: true, unique: true)]
    private ?string $stripeSessionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $paymentIntentId = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $refundedAt = null;

    // Avoir : établi quand la commande est remboursée (facture négative qui annule la facture d'origine). Série à part : AV-2026-0001…
    #[ORM\Column(length: 30, nullable: true, unique: true)]
    private ?string $creditNoteNumber = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $creditNotedAt = null;

    // Expédition : transporteur, numéro et lien de suivi, saisis dans le back-office au passage à « expédiée »
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $carrier = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $trackingNumber = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $trackingUrl = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $shippedAt = null;

    // Stock : vrai quand les pièces réservées par cette commande ont été remises en vente (ou n'ont jamais été réservées :
    // les commandes antérieures à la gestion du stock reçoivent « vrai » par défaut en base). Évite toute restitution en double.
    #[ORM\Column(options: ['default' => true])]
    private bool $stockReleased = false;

    // Facture : numéro unique et continu (FA-2026-0001), attribué au paiement (ou à la première demande pour les anciennes commandes)
    #[ORM\Column(length: 30, nullable: true, unique: true)]
    private ?string $invoiceNumber = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $invoicedAt = null;

    /** @var array{name: ?string, line1: ?string, line2: ?string, postalCode: ?string, city: ?string, state: ?string, country: ?string}|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $shippingAddress = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getReference(): string { return 'CMD-'.(self::REF_OFFSET + (int) $this->id); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $at): static { $this->createdAt = $at; return $this; }
    public function getStatus(): OrderStatus { return $this->status; }
    public function setStatus(OrderStatus $status): static { $this->status = $status; return $this; }
    public function getCustomerName(): string { return $this->customerName; }
    public function getCustomerEmail(): string { return $this->customerEmail; }
    public function setCustomer(string $name, string $email): static { $this->customerName = $name; $this->customerEmail = $email; return $this; }
    public function getItems(): array { return $this->items; }
    public function setItems(array $items): static { $this->items = array_values($items); return $this; }
    public function getTotalCents(): int { return $this->totalCents; }
    public function setTotalCents(int $cents): static { $this->totalCents = $cents; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function getStripeSessionId(): ?string { return $this->stripeSessionId; }
    public function setStripeSessionId(?string $id): static { $this->stripeSessionId = $id; return $this; }
    public function getPaymentIntentId(): ?string { return $this->paymentIntentId; }
    public function setPaymentIntentId(?string $id): static { $this->paymentIntentId = $id; return $this; }
    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function setPaidAt(?\DateTimeImmutable $at): static { $this->paidAt = $at; return $this; }
    public function getRefundedAt(): ?\DateTimeImmutable { return $this->refundedAt; }
    public function setRefundedAt(?\DateTimeImmutable $at): static { $this->refundedAt = $at; return $this; }
    public function getCreditNoteNumber(): ?string { return $this->creditNoteNumber; }
    public function getCreditNotedAt(): ?\DateTimeImmutable { return $this->creditNotedAt; }
    public function setCreditNote(string $number, \DateTimeImmutable $at): static { $this->creditNoteNumber = $number; $this->creditNotedAt = $at; return $this; }
    /** Un avoir existe (ou peut être établi) pour une commande payée puis remboursée. */
    public function isCreditNoteable(): bool
    {
        return $this->refundedAt !== null && ($this->paidAt !== null || $this->invoiceNumber !== null);
    }
    public function getCarrier(): ?string { return $this->carrier; }
    public function getTrackingNumber(): ?string { return $this->trackingNumber; }
    public function getTrackingUrl(): ?string { return $this->trackingUrl; }
    public function getShippedAt(): ?\DateTimeImmutable { return $this->shippedAt; }
    public function setShippedAt(?\DateTimeImmutable $at): static { $this->shippedAt = $at; return $this; }
    public function setTracking(?string $carrier, ?string $number, ?string $url): static
    {
        $this->carrier = $carrier;
        $this->trackingNumber = $number;
        $this->trackingUrl = $url;

        return $this;
    }
    public function isStockReleased(): bool { return $this->stockReleased; }
    public function getInvoiceNumber(): ?string { return $this->invoiceNumber; }
    public function getInvoicedAt(): ?\DateTimeImmutable { return $this->invoicedAt; }
    public function setInvoice(string $number, \DateTimeImmutable $at): static { $this->invoiceNumber = $number; $this->invoicedAt = $at; return $this; }
    /** Une facture existe pour une commande payée (et non remboursée / annulée) */
    public function isInvoiceable(): bool
    {
        if ($this->invoiceNumber !== null) {
            return true; // facture déjà émise : elle reste consultable, même remboursée (l'avoir l'annule)
        }

        return \in_array($this->status, [OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Delivered], true) && $this->refundedAt === null;
    }
    public function getShippingAddress(): ?array { return $this->shippingAddress; }
    public function setShippingAddress(?array $address): static { $this->shippingAddress = $address; return $this; }

    /** Forme exposée au React : identique à une commande du store */
    public function toArray(): array
    {
        return [
            'id' => $this->getReference(),
            'date' => $this->createdAt->format(\DATE_ATOM),
            'status' => $this->status->value,
            'total' => Money::fromCents($this->totalCents),
            'customer' => ['name' => $this->customerName, 'email' => $this->customerEmail],
            'items' => $this->items,
            // Paiement en ligne (online = commande passée via Stripe) et adresse de livraison saisie chez Stripe
            'payment' => [
                'online' => $this->stripeSessionId !== null,
                'paidAt' => $this->paidAt?->format(\DATE_ATOM),
                'refundedAt' => $this->refundedAt?->format(\DATE_ATOM),
            ],
            'shipping' => $this->shippingAddress,
            'invoice' => ['available' => $this->isInvoiceable(), 'number' => $this->invoiceNumber],
            'creditNote' => ['available' => $this->isCreditNoteable(), 'number' => $this->creditNoteNumber],
            'tracking' => ($this->trackingNumber === null && $this->shippedAt === null) ? null : [
                'carrier' => $this->carrier,
                'number' => $this->trackingNumber,
                'url' => $this->trackingUrl,
                'shippedAt' => $this->shippedAt?->format(\DATE_ATOM),
            ],
        ];
    }
}
