<?php

namespace App\Dto;

use App\Entity\QuoteRequest;
use Symfony\Component\Validator\Constraints as Assert;

// Corps (multipart/form-data, comme le formulaire de la page « Demander un devis ») :
// name, email, phone, projectType, widthCm, heightCm, location, description, budget, desiredBy, consent, website, lang + photos[]
final class QuoteInput
{
    #[Assert\NotBlank(message: 'Saisissez votre nom.')]
    #[Assert\Length(max: 120, maxMessage: 'Le nom est trop long (120 caractères maximum).')]
    public string $name = '';

    #[Assert\NotBlank(message: 'Saisissez une adresse e-mail valide.')]
    #[Assert\Email(message: 'Saisissez une adresse e-mail valide.')]
    #[Assert\Length(max: 180, maxMessage: 'L’adresse e-mail est trop longue.')]
    public string $email = '';

    #[Assert\Regex(pattern: '~^[0-9+().\s-]{6,30}$~', message: 'Numéro de téléphone invalide.')]
    public string $phone = '';

    #[Assert\Choice(choices: QuoteRequest::PROJECT_TYPES, message: 'Choisissez intérieur ou extérieur.')]
    public string $projectType = '';

    #[Assert\Range(min: 1, max: 5000, notInRangeMessage: 'Dimension de 1 à 5000 cm.')]
    public ?int $widthCm = null;

    #[Assert\Range(min: 1, max: 5000, notInRangeMessage: 'Dimension de 1 à 5000 cm.')]
    public ?int $heightCm = null;

    #[Assert\Length(max: 160, maxMessage: 'Le lieu est trop long (160 caractères maximum).')]
    public string $location = '';

    #[Assert\NotBlank(message: 'Décrivez votre projet (au moins 10 caractères).')]
    #[Assert\Length(min: 10, max: 3000,
        minMessage: 'Décrivez votre projet (au moins 10 caractères).',
        maxMessage: 'La description est trop longue (3000 caractères maximum).')]
    public string $description = '';

    #[Assert\Choice(choices: QuoteRequest::BUDGETS, message: 'Budget invalide.')]
    public ?string $budget = null;

    #[Assert\Length(max: 80, maxMessage: 'Texte trop long (80 caractères maximum).')]
    public string $desiredBy = '';

    #[Assert\IsTrue(message: 'Veuillez accepter l’utilisation de vos données pour recevoir une réponse.')]
    public bool $consent = false;

    // Piège à robots : champ invisible pour les humains, il doit rester vide
    public string $website = '';

    public string $lang = 'fr';

    public static function fromArray(array $d): self
    {
        $line = static fn (mixed $v): string => is_string($v) ? trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $v)) : '';
        // Nombre entier saisi dans un champ texte ; valeur illisible -> 0 (refusée par la contrainte)
        $int = static function (mixed $v): ?int {
            if ($v === null || $v === '') {
                return null;
            }
            $v = is_string($v) ? trim($v) : $v;

            return (is_int($v) || (is_string($v) && ctype_digit($v))) ? (int) $v : 0;
        };

        $in = new self();
        $in->name = $line($d['name'] ?? null);
        $in->email = mb_strtolower($line($d['email'] ?? null));
        $in->phone = $line($d['phone'] ?? null);
        $in->projectType = is_string($d['projectType'] ?? null) ? $d['projectType'] : '';
        $in->widthCm = $int($d['widthCm'] ?? null);
        $in->heightCm = $int($d['heightCm'] ?? null);
        $in->location = $line($d['location'] ?? null);
        $in->description = is_string($d['description'] ?? null) ? trim((string) preg_replace('/[^\P{Cc}\n]+/u', '', str_replace("\r\n", "\n", $d['description']))) : '';
        $in->budget = is_string($d['budget'] ?? null) && $d['budget'] !== '' ? $d['budget'] : null;
        $in->desiredBy = $line($d['desiredBy'] ?? null);
        $in->consent = filter_var($d['consent'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $in->website = is_string($d['website'] ?? null) ? trim($d['website']) : '';
        $in->lang = ($d['lang'] ?? 'fr') === 'en' ? 'en' : 'fr';

        return $in;
    }
}
