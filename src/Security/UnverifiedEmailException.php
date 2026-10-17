<?php

namespace App\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

// Compte existant mais e-mail pas encore confirmé (levée seulement APRÈS vérification du mot de passe,
// pour ne pas révéler quelles adresses sont inscrites)
final class UnverifiedEmailException extends CustomUserMessageAccountStatusException
{
    public function __construct()
    {
        parent::__construct('Veuillez confirmer votre adresse e-mail avant de vous connecter.');
    }
}
