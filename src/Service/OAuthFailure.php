<?php

namespace App\Service;

// Échec d'une connexion Google / Facebook. `reason` est repris dans l'adresse de retour du React (?oauth=<reason>) :
// cancelled, invalid_state, failed, disabled, email_missing, account_exists, verify_email
final class OAuthFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('OAuth : '.$reason);
    }
}
