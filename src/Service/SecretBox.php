<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Chiffre les secrets stockés en base (secret de la double authentification) : une base de données volée seule ne permet pas
// de générer les codes. Clé dérivée d'APP_SECRET : NE CHANGEZ PAS APP_SECRET sans avoir désactivé la double authentification
// (sinon les secrets deviennent illisibles ; `php bin/console app:disable-2fa <e-mail>` permet alors de s'en sortir).
final class SecretBox
{
    private ?string $key = null;

    public function __construct(#[Autowire('%kernel.secret%')] private readonly string $secret)
    {
    }

    // Clé calculée à la première utilisation seulement : la connexion d'un compte SANS double authentification ne dépend jamais de sodium
    private function key(): string
    {
        return $this->key ??= sodium_crypto_generichash('dab-secretbox|'.$this->secret, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function seal(string $plain): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    public function open(string $sealed): ?string
    {
        $raw = base64_decode($sealed, true);
        if ($raw === false || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key(),
        );

        return $plain === false ? null : $plain;
    }
}
