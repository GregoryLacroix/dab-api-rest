<?php

namespace App\Service;

// Erreur de validation : { champ => message }, renvoyée en HTTP 422
final class ValidationFailedException extends \RuntimeException
{
    /** @param array<string, string> $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Certains champs sont invalides.');
    }
}
