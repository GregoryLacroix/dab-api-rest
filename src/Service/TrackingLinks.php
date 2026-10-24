<?php

namespace App\Service;

// Transporteurs proposés dans le back-office et modèle d'adresse de suivi ({n} = numéro). Si un lien ne fonctionne plus chez un
// transporteur, le champ « lien de suivi » du back-office le remplace : rien n'est figé ici.
final class TrackingLinks
{
    public const CARRIERS = [
        'Colissimo' => 'https://www.laposte.fr/outils/suivre-vos-envois?code={n}',
        'Lettre suivie' => 'https://www.laposte.fr/outils/suivre-vos-envois?code={n}',
        'Chronopost' => 'https://www.chronopost.fr/tracking-no-cms/suivi-page?listeNumerosLT={n}',
        'Mondial Relay' => 'https://www.mondialrelay.fr/suivi-de-colis/?numeroExpedition={n}',
        'DHL' => 'https://www.dhl.com/fr-fr/home/tracking.html?tracking-id={n}',
        'UPS' => 'https://www.ups.com/track?loc=fr_FR&tracknum={n}',
    ];

    public static function urlFor(?string $carrier, string $number): ?string
    {
        $template = self::CARRIERS[$carrier ?? ''] ?? null;

        return $template === null ? null : str_replace('{n}', rawurlencode($number), $template);
    }
}
