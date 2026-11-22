<?php

namespace App\Entity;

use App\Repository\StockAlertRepository;
use Doctrine\ORM\Mapping as ORM;

// « Prévenez-moi » : un visiteur demande à être prévenu par e-mail quand un produit épuisé redevient disponible.
// Une seule alerte par produit et par adresse ; la ligne est supprimée une fois l'e-mail envoyé (on ne garde pas l'adresse).
#[ORM\Entity(repositoryClass: StockAlertRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_stock_alert', columns: ['product_id', 'email'])]
class StockAlert
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Pas de clé étrangère (voir docs/*.sql) : si le produit est supprimé, l'alerte est ignorée puis nettoyée
    #[ORM\Column]
    private int $productId;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 2)]
    private string $lang = 'fr';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(int $productId, string $email, string $lang)
    {
        $this->productId = $productId;
        $this->email = mb_strtolower($email);
        $this->lang = $lang === 'en' ? 'en' : 'fr';
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getProductId(): int { return $this->productId; }
    public function getEmail(): string { return $this->email; }
    public function getLang(): string { return $this->lang; }
}
