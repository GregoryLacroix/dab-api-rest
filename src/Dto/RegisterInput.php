<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

// Champs et messages = formulaire "Créer un compte" du React (name, email, password, confirm)
final class RegisterInput
{
    #[Assert\NotBlank(message: 'Saisissez votre nom.')]
    #[Assert\Length(max: 120, maxMessage: 'Le nom est trop long (120 caractères maximum).')]
    public string $name = '';

    #[Assert\NotBlank(message: 'Saisissez une adresse e-mail valide.')]
    #[Assert\Email(message: 'Saisissez une adresse e-mail valide.')]
    #[Assert\Length(max: 180, maxMessage: 'L’adresse e-mail est trop longue.')]
    public string $email = '';

    #[Assert\NotBlank(message: 'Le mot de passe doit contenir 8 caractères minimum.')]
    #[Assert\Length(min: 8, max: 200, minMessage: 'Le mot de passe doit contenir 8 caractères minimum.', maxMessage: 'Le mot de passe est trop long.')]
    public string $password = '';

    public ?string $confirm = null;

    // Acceptation de la politique de confidentialité et des CGV (case à cocher du formulaire)
    #[Assert\IsTrue(message: 'Veuillez accepter la politique de confidentialité et les conditions générales de vente.')]
    public bool $consent = false;

    public static function fromArray(array $d): self
    {
        $in = new self();
        $in->name = is_string($d['name'] ?? null) ? trim($d['name']) : '';
        $in->email = is_string($d['email'] ?? null) ? mb_strtolower(trim($d['email'])) : '';
        $in->password = is_string($d['password'] ?? null) ? $d['password'] : '';
        $in->confirm = is_string($d['confirm'] ?? null) ? $d['confirm'] : null;
        $in->consent = filter_var($d['consent'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $in;
    }

    #[Assert\Callback]
    public function validateConfirm(ExecutionContextInterface $context): void
    {
        if ($this->confirm !== null && $this->confirm !== $this->password) {
            $context->buildViolation('Les mots de passe ne correspondent pas.')->atPath('confirm')->addViolation();
        }
    }
}
