<?php

namespace App\Entity;

use App\Repository\RealisationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// Réalisation : une fresque ou un décor mural déjà peint (galerie publique, sert de portfolio pour les demandes de devis).
#[ORM\Entity(repositoryClass: RealisationRepository::class)]
class Realisation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $title = '';

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $titleEn = null;

    // Ville / lieu (ex. « Chambre d'enfant, Lyon »)
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $location = null;

    // Catégorie d'affichage, sert de filtre (ex. « Chambre d'enfant », « Commerce »)
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $tag = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $tagEn = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descriptionEn = null;

    /** @var string[] adresses des photos (la première sert de couverture) */
    #[ORM\Column(type: Types::JSON)]
    private array $images = [];

    // Photo « avant » (facultative) : affichée à côté de la première photo
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $beforeImage = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column]
    private bool $published = true;

    // Ordre d'affichage (le plus petit d'abord)
    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $v): static { $this->title = $v; return $this; }
    public function setTitleEn(?string $v): static { $this->titleEn = $v; return $this; }
    public function setLocation(?string $v): static { $this->location = $v; return $this; }
    public function setTag(?string $v): static { $this->tag = $v; return $this; }
    public function setTagEn(?string $v): static { $this->tagEn = $v; return $this; }
    public function setDescription(?string $v): static { $this->description = $v; return $this; }
    public function setDescriptionEn(?string $v): static { $this->descriptionEn = $v; return $this; }
    /** @param string[] $v */
    public function setImages(array $v): static { $this->images = array_values($v); return $this; }
    public function setBeforeImage(?string $v): static { $this->beforeImage = $v; return $this; }
    public function setCompletedAt(?\DateTimeImmutable $v): static { $this->completedAt = $v; return $this; }
    public function isPublished(): bool { return $this->published; }
    public function setPublished(bool $v): static { $this->published = $v; return $this; }
    public function setPosition(int $v): static { $this->position = $v; return $this; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'titleEn' => $this->titleEn,
            'location' => $this->location,
            'tag' => $this->tag,
            'tagEn' => $this->tagEn,
            'description' => $this->description,
            'descriptionEn' => $this->descriptionEn,
            'images' => $this->images,
            'beforeImage' => $this->beforeImage,
            'completedAt' => $this->completedAt?->format('Y-m-d'),
            'published' => $this->published,
            'position' => $this->position,
        ];
    }
}
