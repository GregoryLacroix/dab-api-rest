<?php

namespace App\Service;

// Codes à usage unique basés sur l'heure (TOTP, RFC 6238), compatibles avec Google Authenticator, Microsoft Authenticator, Authy, 1Password…
// Algorithme vérifié avec les vecteurs de test officiels de la RFC (SHA-1, 6 chiffres, pas de 30 secondes).
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Nouveau secret : 160 bits aléatoires, écrits en base 32 (32 caractères). */
    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** Adresse lue par le QR code de l'application d'authentification. */
    public static function otpauthUrl(string $issuer, string $account, string $secret): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?'.http_build_query([
            'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::PERIOD,
        ], '', '&', \PHP_QUERY_RFC3986);
    }

    public function codeAt(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = \ord($hash[19]) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', \STR_PAD_LEFT);
    }

    /**
     * Accepte le code de la période courante, de la précédente et de la suivante (décalage d'horloge d'un téléphone).
     *
     * @param int|null $afterStep dernier pas déjà utilisé : un code déjà accepté ne peut pas servir deux fois
     *
     * @return int|null pas de temps correspondant (à mémoriser), null si le code est faux
     */
    public function verify(string $secret, string $code, ?int $afterStep = null, ?int $now = null): ?int
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $current = intdiv($now ?? time(), self::PERIOD);
        for ($step = $current - 1; $step <= $current + 1; ++$step) {
            if ($afterStep !== null && $step <= $afterStep) {
                continue;
            }
            if (hash_equals($this->codeAt($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    private static function base32Encode(string $binary): string
    {
        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(\ord($char)), 8, '0', \STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private static function base32Decode(string $base32): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($base32, '='))) as $char) {
            $pos = strpos(self::ALPHABET, $char);
            if ($pos !== false) {
                $bits .= str_pad(decbin($pos), 5, '0', \STR_PAD_LEFT);
            }
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (\strlen($byte) === 8) {
                $out .= \chr(bindec($byte));
            }
        }

        return $out;
    }
}
