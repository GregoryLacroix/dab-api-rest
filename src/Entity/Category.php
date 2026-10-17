<?php

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
class Category
{
    // Identifiant "slug" ("galets", "fresques"), comme dans le React
    #[ORM\Id]
    #[ORM\Column(length: 120)]
    private string $id = '';

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<int, Product> */
    #[ORM\OneToMany(mappedBy: 'category', targetEntity: Product::class, fetch: 'EXTRA_LAZY')]
    private Collection $products;

    public function __construct()
    {
        $this->products = new ArrayCollection();
    }

    public function getId(): string { return $this->id; }
    public function setId(string $id): static { $this->id = $id; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): static { $this->position = $position; return $this; }

    /** @return Collection<int, Product> */
    public function getProducts(): Collection { return $this->products; }

    /** Forme exposée au React ({ id, name }) + nombre de produits */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'productCount' => $this->products->count()];
    }
}
