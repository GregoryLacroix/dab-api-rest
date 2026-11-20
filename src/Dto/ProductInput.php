<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

// Corps attendu = un produit du store React : { name, categoryId, price, description, published, images[] }
final class ProductInput
{
    public const TONES = ['sea', 'sky', 'cliff', 'wall'];
    public const MAX_IMAGES = 8;

    #[Assert\NotBlank(message: 'Saisissez le nom du produit.')]
    #[Assert\Length(max: 160, maxMessage: 'Le nom est trop long (160 caractères maximum).')]
    public string $name = '';

    #[Assert\NotBlank(message: 'Choisissez une catégorie (créez-en une si besoin).')]
    public string $categoryId = '';

    #[Assert\NotNull(message: 'Saisissez un prix valide.')]
    #[Assert\PositiveOrZero(message: 'Saisissez un prix valide.')]
    #[Assert\LessThanOrEqual(value: 100000, message: 'Le prix est trop élevé.')]
    public ?float $price = null;

    #[Assert\Length(max: 5000, maxMessage: 'La description est trop longue (5000 caractères maximum).')]
    public string $description = '';

    public bool $published = true;

    // null = ne pas modifier (nouveau produit : 1 pièce)
    #[Assert\Range(min: 0, max: 9999, notInRangeMessage: 'Quantité en stock invalide (de 0 à 9999).')]
    public ?int $stock = null;

    // Caractéristiques facultatives (null = non renseigné)
    #[Assert\Length(max: 60, maxMessage: 'La taille est trop longue (60 caractères maximum).')]
    public ?string $sizeLabel = null;

    #[Assert\Range(min: 0.1, max: 1000, notInRangeMessage: 'Largeur invalide (de 0,1 à 1000 cm).')]
    public ?float $widthCm = null;

    #[Assert\Range(min: 0.1, max: 1000, notInRangeMessage: 'Hauteur invalide (de 0,1 à 1000 cm).')]
    public ?float $heightCm = null;

    #[Assert\Range(min: 0.1, max: 1000, notInRangeMessage: 'Épaisseur invalide (de 0,1 à 1000 cm).')]
    public ?float $depthCm = null;

    #[Assert\Range(min: 1, max: 500000, notInRangeMessage: 'Poids invalide (de 1 à 500 000 g).')]
    public ?int $weightG = null;

    /** @var array<int, mixed> */
    public array $images = [];

    public static function fromArray(array $d): self
    {
        $in = new self();
        $in->name = is_string($d['name'] ?? null) ? trim($d['name']) : '';
        $in->categoryId = is_string($d['categoryId'] ?? null) ? trim($d['categoryId']) : '';
        $price = $d['price'] ?? null;
        $in->price = (is_int($price) || is_float($price) || (is_string($price) && is_numeric($price)))
            ? (float) $price : null;
        $in->description = is_string($d['description'] ?? null) ? trim($d['description']) : '';
        $in->published = array_key_exists('published', $d) ? filter_var($d['published'], FILTER_VALIDATE_BOOLEAN) : true;
        $in->images = is_array($d['images'] ?? null) ? array_values($d['images']) : [];
        if (array_key_exists('stock', $d) && $d['stock'] !== null && $d['stock'] !== '') {
            $stock = $d['stock'];
            // Valeur non numérique -> -1 : refusée par la contrainte ci-dessus
            $in->stock = (is_int($stock) || (is_string($stock) && ctype_digit(trim($stock)))) ? (int) $stock : -1;
        }

        $in->sizeLabel = isset($d['sizeLabel']) && is_string($d['sizeLabel']) && trim($d['sizeLabel']) !== '' ? trim($d['sizeLabel']) : null;
        foreach (['widthCm', 'heightCm', 'depthCm'] as $k) {
            $in->$k = self::num($d[$k] ?? null);
        }
        $w = self::num($d['weightG'] ?? null);
        $in->weightG = $w === null ? null : (int) round($w);

        return $in;
    }

    /** Nombre facultatif : vide/null -> null ; texte non numérique -> -1 (refusé par la contrainte de plage) */
    private static function num(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_string($v)) {
            $v = str_replace(',', '.', trim($v));
        }

        return is_numeric($v) ? round((float) $v, 1) : -1.0;
    }

    /** Photos : { src } (URL, chemin ou image encodée) ou { tone } (visuel bleu provisoire), + alt */
    #[Assert\Callback]
    public function validateImages(ExecutionContextInterface $context): void
    {
        if (count($this->images) > self::MAX_IMAGES) {
            $context->buildViolation('Maximum '.self::MAX_IMAGES.' photos.')->atPath('images')->addViolation();

            return;
        }
        foreach ($this->images as $i => $img) {
            $fail = fn (string $field, string $msg) => $context->buildViolation($msg)->atPath("images.$i.$field")->addViolation();
            if (!is_array($img)) {
                $context->buildViolation('Image invalide.')->atPath("images.$i")->addViolation();
                continue;
            }
            $src = $img['src'] ?? null;
            $tone = $img['tone'] ?? null;
            $alt = $img['alt'] ?? '';
            if ($src === null && $tone === null) {
                $fail('src', 'Chaque image doit avoir une source (src) ou un visuel (tone).');
            }
            if ($src !== null && !self::isValidSrc($src)) {
                $fail('src', 'Adresse d’image invalide (http(s)://, chemin commençant par / ou image encodée).');
            }
            if ($tone !== null && !in_array($tone, self::TONES, true)) {
                $fail('tone', 'Visuel invalide (sea, sky, cliff ou wall).');
            }
            if (!is_string($alt) || mb_strlen($alt) > 200) {
                $fail('alt', 'Texte alternatif invalide (200 caractères maximum).');
            }
        }
    }

    private static function isValidSrc(mixed $src): bool
    {
        if (!is_string($src) || $src === '' || strlen($src) > 1_500_000) {
            return false;
        }
        if (str_starts_with($src, 'data:')) {
            return (bool) preg_match('~^data:image/(png|jpeg|webp|gif);base64,~', substr($src, 0, 40));
        }

        return strlen($src) <= 2000 && (bool) preg_match('~^(https?://\S+|/\S*)$~', $src);
    }

    /** @return array<int, array{src?: string, tone?: string, alt: string}> images nettoyées, prêtes à être stockées */
    public function cleanImages(): array
    {
        $out = [];
        foreach ($this->images as $i => $img) {
            $clean = [];
            if (isset($img['src'])) {
                $clean['src'] = $img['src'];
            }
            if (isset($img['tone'])) {
                $clean['tone'] = $img['tone'];
            }
            $clean['alt'] = (isset($img['alt']) && $img['alt'] !== '') ? $img['alt'] : trim($this->name).' – image '.($i + 1);
            $out[] = $clean;
        }

        return $out;
    }
}
