<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

// Corps attendu : { rating: 1..5, title?: string, body: string }
final class ReviewInput
{
    #[Assert\Range(min: 1, max: 5, notInRangeMessage: 'Choisissez une note de 1 à 5 étoiles.')]
    public int $rating = 0;

    #[Assert\Length(max: 100, maxMessage: 'Le titre est trop long (100 caractères maximum).')]
    public ?string $title = null;

    #[Assert\NotBlank(message: 'Votre avis doit contenir au moins 10 caractères.')]
    #[Assert\Length(min: 10, max: 2000,
        minMessage: 'Votre avis doit contenir au moins 10 caractères.',
        maxMessage: 'Votre avis est trop long (2000 caractères maximum).')]
    public string $body = '';

    public static function fromArray(array $d): self
    {
        $in = new self();
        $rating = $d['rating'] ?? null;
        $in->rating = (is_int($rating) || (is_string($rating) && ctype_digit($rating))) ? (int) $rating : 0;
        $title = is_string($d['title'] ?? null) ? trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $d['title'])) : '';
        $in->title = $title === '' ? null : $title;
        // le texte garde ses retours à la ligne, mais pas les caractères de contrôle
        $in->body = is_string($d['body'] ?? null) ? trim((string) preg_replace('/[^\P{Cc}\n]+/u', '', str_replace("\r\n", "\n", $d['body']))) : '';

        return $in;
    }
}
