<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CategoryInput
{
    #[Assert\NotBlank(message: 'Saisissez un nom de catégorie.')]
    #[Assert\Length(max: 120, maxMessage: 'Le nom est trop long (120 caractères maximum).')]
    public string $name = '';

    public static function fromArray(array $d): self
    {
        $in = new self();
        $in->name = is_string($d['name'] ?? null) ? trim($d['name']) : '';

        return $in;
    }
}
