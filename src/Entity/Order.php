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
    public function getInvoiceNumber(): ?string { return $this->invoiceNumber; }
    public function getInvoicedAt(): ?\DateTimeImmutable { return $this->invoicedAt; }
    public function setInvoice(string $number, \DateTimeImmutable $at): static { $this->invoiceNumber = $number; $this->invoicedAt = $at; return $this; }
    /** Une facture existe pour une commande payée (et non remboursée / annulée) */
    public function isInvoiceable(): bool
    {
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
        ];
    }
}
