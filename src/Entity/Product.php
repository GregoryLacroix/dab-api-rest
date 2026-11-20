<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $name = '';

    #[ORM\ManyToOne(inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Category $category;

    #[ORM\Column]
    private int $priceCents = 0;

    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    #[ORM\Column]
    private bool $published = true;

    // Quantité disponible (chaque galet est une pièce unique : 1 par défaut). 0 = vendu.
    #[ORM\Column(options: ['default' => 1])]
    private int $stock = 1;

    // Traduction anglaise (facultative) : sans elle, le site anglais affiche le texte français
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $nameEn = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descriptionEn = null;

    // Caractéristiques (toutes facultatives) : taille libre (« Ø 8 cm », « Moyen »), dimensions en cm, poids en grammes
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $sizeLabel = null;

    #[ORM\Column(nullable: true)]
    private ?float $widthCm = null;

    #[ORM\Column(nullable: true)]
    private ?float $heightCm = null;

    #[ORM\Column(nullable: true)]
    private ?float $depthCm = null;

    #[ORM\Column(nullable: true)]
    private ?int $weightG = null;

    /** @var array<int, array{src?: string, tone?: string, alt: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $images = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getCategory(): Category { return $this->category; }
    public function setCategory(Category $category): static { $this->category = $category; return $this; }
    public function getPriceCents(): int { return $this->priceCents; }
    public function setPriceCents(int $cents): static { $this->priceCents = $cents; return $this; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): static { $this->description = $description; return $this; }
    public function isPublished(): bool { return $this->published; }
    public function setPublished(bool $published): static { $this->published = $published; return $this; }
    public function getStock(): int { return $this->stock; }
    public function setStock(int $stock): static { $this->stock = max(0, $stock); return $this; }
    public function getNameEn(): ?string { return $this->nameEn; }
    public function getDescriptionEn(): ?string { return $this->descriptionEn; }
    public function setEnglish(?string $name, ?string $description): static
    {
        $this->nameEn = $name === null || $name === '' ? null : $name;
        $this->descriptionEn = $description === null || $description === '' ? null : $description;

        return $this;
    }

    /** Textes anglais renseignés, sous la forme attendue par le React : { en: { name?, description? } } ; [] si aucun */
    public function translations(): array
    {
        $en = array_filter(['name' => $this->nameEn, 'description' => $this->descriptionEn], static fn ($v) => $v !== null);

        return $en === [] ? [] : ['en' => $en];
    }

    public function getSizeLabel(): ?string { return $this->sizeLabel; }
    public function setSizeLabel(?string $v): static { $this->sizeLabel = $v === '' ? null : $v; return $this; }
    public function getWidthCm(): ?float { return $this->widthCm; }
    public function setWidthCm(?float $v): static { $this->widthCm = $v; return $this; }
    public function getHeightCm(): ?float { return $this->heightCm; }
    public function setHeightCm(?float $v): static { $this->heightCm = $v; return $this; }
    public function getDepthCm(): ?float { return $this->depthCm; }
    public function setDepthCm(?float $v): static { $this->depthCm = $v; return $this; }
    public function getWeightG(): ?int { return $this->weightG; }
    public function setWeightG(?int $v): static { $this->weightG = $v; return $this; }
    public function getImages(): array { return $this->images; }
    public function setImages(array $images): static { $this->images = array_values($images); return $this; }

    /** Forme exposée au React : identique à un produit du store */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'categoryId' => $this->category->getId(),
            'price' => Money::fromCents($this->priceCents),
            'published' => $this->published,
            'description' => $this->description,
            'stock' => $this->stock,
            'sizeLabel' => $this->sizeLabel,
            'widthCm' => $this->widthCm,
            'heightCm' => $this->heightCm,
            'depthCm' => $this->depthCm,
            'weightG' => $this->weightG,
            'images' => $this->images,
        ] + ($this->translations() === [] ? [] : ['translations' => $this->translations()]);
    }
}
