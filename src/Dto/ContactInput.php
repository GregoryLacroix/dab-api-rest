<?php

namespace App\Dto;

use App\Entity\ContactMessage;
use Symfony\Component\Validator\Constraints as Assert;

// Corps attendu = le formulaire de la page Contact : { name, email, subject, message, consent, website }
final class ContactInput
{
    #[Assert\NotBlank(message: 'Saisissez votre nom.')]
    #[Assert\Length(max: 120, maxMessage: 'Le nom est trop long (120 caractères maximum).')]
    public string $name = '';

    #[Assert\NotBlank(message: 'Saisissez une adresse e-mail valide.')]
    #[Assert\Email(message: 'Saisissez une adresse e-mail valide.')]
    #[Assert\Length(max: 180, maxMessage: 'L’adresse e-mail est trop longue.')]
    public string $email = '';

    #[Assert\Choice(choices: ['produit', 'commande', 'sur-mesure', 'autre'], message: 'Choisissez un sujet.')]
    public string $subject = '';

    #[Assert\NotBlank(message: 'Votre message doit contenir au moins 10 caractères.')]
    #[Assert\Length(min: 10, max: 3000,
        minMessage: 'Votre message doit contenir au moins 10 caractères.',
        maxMessage: 'Votre message est trop long (3000 caractères maximum).')]
    public string $message = '';

    #[Assert\IsTrue(message: 'Veuillez accepter l’utilisation de vos données pour recevoir une réponse.')]
    public bool $consent = false;

    // Piège à robots : champ invisible pour les humains, il doit rester vide
    public string $website = '';

    public static function fromArray(array $d): self
    {
        $line = static fn (mixed $v): string => is_string($v) ? trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $v)) : '';

        $in = new self();
        $in->name = $line($d['name'] ?? null);
        $in->email = mb_strtolower($line($d['email'] ?? null));
        $in->subject = is_string($d['subject'] ?? null) ? $d['subject'] : '';
        // le message garde ses retours à la ligne, mais pas les caractères de contrôle
        $in->message = is_string($d['message'] ?? null) ? trim((string) preg_replace('/[^\P{Cc}\n]+/u', '', str_replace("\r\n", "\n", $d['message']))) : '';
        $in->consent = filter_var($d['consent'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $in->website = is_string($d['website'] ?? null) ? trim($d['website']) : '';

        return $in;
    }

    /** @return array<int, string> clés des sujets autorisés (doit rester synchronisé avec ContactMessage::SUBJECTS) */
    public static function subjects(): array
    {
        return array_keys(ContactMessage::SUBJECTS);
    }
}
