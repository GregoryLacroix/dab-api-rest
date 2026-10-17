<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// Réglages du site stockés en JSON (le contenu éditable depuis le back-office : clé "content")
#[ORM\Entity]
#[ORM\Table(name: 'app_setting')]
class Setting
{
    #[ORM\Id]
    #[ORM\Column(length: 60)]
    private string $name;

    #[ORM\Column(type: Types::JSON)]
    private array $value = [];

    public function __construct(string $name, array $value = [])
    {
        $this->name = $name;
        $this->value = $value;
    }

    public function getValue(): array { return $this->value; }
    public function setValue(array $value): static { $this->value = $value; return $this; }
}
