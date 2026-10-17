<?php

namespace App\Entity;

use App\Repository\ContactMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContactMessageRepository::class)]
class ContactMessage
{
    // Mêmes clés et libellés que SUBJECTS côté React
    public const SUBJECTS = [
        'produit' => 'Question sur un produit',
        'commande' => 'Suivi de commande',
        'sur-mesure' => 'Galet ou fresque sur mesure',
        'autre' => 'Autre demande',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 180)]
    private string $email = '';

    #[ORM\Column(length: 20)]
    private string $subject = 'autre';

    #[ORM\Column(type: Types::TEXT)]
    private string $message = '';

    #[ORM\Column]
    private bool $isRead = false;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $at): static { $this->createdAt = $at; return $this; }
    public function getName(): string { return $this->name; }
    public function getEmail(): string { return $this->email; }
    public function getSubject(): string { return $this->subject; }
    public function getSubjectLabel(): string { return self::SUBJECTS[$this->subject] ?? 'Autre demande'; }
    public function getMessage(): string { return $this->message; }
    public function isRead(): bool { return $this->isRead; }
    public function setRead(bool $read): static { $this->isRead = $read; return $this; }

    public function setContent(string $name, string $email, string $subject, string $message): static
    {
        $this->name = $name;
        $this->email = $email;
        $this->subject = $subject;
        $this->message = $message;

        return $this;
    }

    /** Forme exposée au React : identique à un message du store */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->createdAt->format(\DATE_ATOM),
            'name' => $this->name,
            'email' => $this->email,
            'subject' => $this->subject,
            'message' => $this->message,
            'read' => $this->isRead,
        ];
    }
}
