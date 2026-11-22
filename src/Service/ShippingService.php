<?php

namespace App\Service;

use App\Entity\Money;
use App\Entity\Setting;
use Doctrine\ORM\EntityManagerInterface;

// Frais de port réels : tarifs par zone (liste de pays) et par tranche de poids, réglables dans le back-office (Setting « shipping »).
// Tant que « enabled » est faux, rien ne change : pas de frais calculés par le site (ancien comportement, tarif Stripe éventuel).
final class ShippingService
{
    private const KEY = 'shipping';

    public const DEFAULTS = [
        'enabled' => false,
        'defaultItemWeightG' => 150,   // poids retenu pour un produit dont le poids n'est pas renseigné
        'packagingG' => 50,            // emballage ajouté au poids du colis
        'zones' => [
            ['name' => 'France', 'countries' => ['FR'], 'freeFrom' => null, 'brackets' => [
                ['upToG' => 250, 'price' => 4.95], ['upToG' => 500, 'price' => 6.50], ['upToG' => 1000, 'price' => 8.00],
                ['upToG' => 2000, 'price' => 9.50], ['upToG' => 5000, 'price' => 14.00],
            ]],
            ['name' => 'Europe', 'countries' => ['BE', 'LU', 'DE', 'NL', 'IT', 'ES', 'PT', 'AT', 'IE'], 'freeFrom' => null, 'brackets' => [
                ['upToG' => 250, 'price' => 8.00], ['upToG' => 500, 'price' => 10.00], ['upToG' => 1000, 'price' => 13.00],
                ['upToG' => 2000, 'price' => 17.00], ['upToG' => 5000, 'price' => 25.00],
            ]],
        ],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** Réglages enregistrés (complétés par les valeurs par défaut). */
    public function get(): array
    {
        $saved = $this->em->find(Setting::class, self::KEY)?->getValue() ?? [];

        return array_replace(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
    }

    /** Pays livrables (codes ISO), toutes zones confondues. */
    public function countries(?array $settings = null): array
    {
        $s = $settings ?? $this->get();

        return array_values(array_unique(array_merge(...array_map(static fn (array $z) => $z['countries'], $s['zones'] ?: [['countries' => []]]))));
    }

    /** Vue publique : pays livrables et seuil de livraison offerte de chaque zone. */
    public function publicInfo(): array
    {
        $s = $this->get();

        return [
            'enabled' => $s['enabled'],
            'zones' => array_map(static fn (array $z) => ['name' => $z['name'], 'countries' => $z['countries'], 'freeFrom' => $z['freeFrom']], $s['zones']),
        ];
    }

    /**
     * Poids d'un colis en grammes.
     *
     * @param array<int, int> $qtyById
     * @param array<int, \App\Entity\Product> $products
     */
    public function weightOf(array $qtyById, array $products): int
    {
        $s = $this->get();
        $g = $s['packagingG'];
        foreach ($qtyById as $id => $qty) {
            $product = $products[$id] ?? null;
            if ($product !== null) {
                $g += ($product->getWeightG() ?? $s['defaultItemWeightG']) * $qty;
            }
        }

        return $g;
    }

    /**
     * @param int $netItemsCents articles après remises (sert au seuil de livraison offerte)
     * @return array{cents: int, country: ?string, zone: ?string}
     */
    public function quote(int $weightG, int $netItemsCents, ?string $country): array
    {
        $s = $this->get();
        if (!$s['enabled']) {
            return ['cents' => 0, 'country' => null, 'zone' => null];
        }
        $country = strtoupper(trim((string) $country));
        foreach ($s['zones'] as $zone) {
            if (!\in_array($country, $zone['countries'], true)) {
                continue;
            }
            if ($zone['freeFrom'] !== null && $netItemsCents >= Money::toCents($zone['freeFrom'])) {
                return ['cents' => 0, 'country' => $country, 'zone' => $zone['name']];
            }
            foreach ($zone['brackets'] as $b) {
                if ($weightG <= $b['upToG']) {
                    return ['cents' => Money::toCents($b['price']), 'country' => $country, 'zone' => $zone['name']];
                }
            }
            throw new ValidationFailedException(['country' => 'Ce colis est trop lourd pour la livraison en ligne : contactez-nous pour un tarif.']);
        }

        throw new ValidationFailedException(['country' => 'Nous ne livrons pas encore ce pays.']);
    }

    /** Enregistre les réglages après validation (le back-office envoie tout le bloc). */
    public function update(array $in): array
    {
        $err = [];
        $out = ['enabled' => (bool) ($in['enabled'] ?? false)];
        foreach (['defaultItemWeightG' => [1, 50000], 'packagingG' => [0, 5000]] as $k => [$min, $max]) {
            $v = $in[$k] ?? self::DEFAULTS[$k];
            if (!is_numeric($v) || $v < $min || $v > $max || (int) $v != $v) {
                $err[$k] = "Entier de $min à $max.";
            }
            $out[$k] = (int) $v;
        }
        $zones = [];
        $seen = [];
        $rows = \is_array($in['zones'] ?? null) ? array_values($in['zones']) : [];
        if (\count($rows) > 8) {
            $err['zones'] = '8 zones au maximum.';
        }
        foreach (array_slice($rows, 0, 8) as $i => $z) {
            $z = \is_array($z) ? $z : [];
            $name = \is_string($z['name'] ?? null) ? trim($z['name']) : '';
            if ($name === '' || mb_strlen($name) > 40) {
                $err["zones.$i.name"] = 'Nom de zone requis (40 caractères maximum).';
            }
            $countries = [];
            $list = \is_array($z['countries'] ?? null) ? $z['countries'] : preg_split('/[\s,;]+/', (string) ($z['countries'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($list as $c) {
                $c = strtoupper(trim((string) $c));
                if (!preg_match('/^[A-Z]{2}$/', $c)) {
                    $err["zones.$i.countries"] = 'Codes pays à 2 lettres (ex. FR, BE, DE), séparés par des virgules.';
                } elseif (isset($seen[$c])) {
                    $err["zones.$i.countries"] = "Le pays $c figure dans deux zones.";
                } else {
                    $seen[$c] = true;
                    $countries[] = $c;
                }
            }
            if ($countries === [] && !isset($err["zones.$i.countries"])) {
                $err["zones.$i.countries"] = 'Indiquez au moins un pays.';
            }
            $free = $z['freeFrom'] ?? null;
            if ($free === '' || $free === null) {
                $free = null;
            } elseif (!is_numeric($free) || $free <= 0 || $free > 10000) {
                $err["zones.$i.freeFrom"] = 'Montant invalide.';
            } else {
                $free = round((float) $free, 2);
            }
            $brackets = [];
            $prev = 0;
            $bs = \is_array($z['brackets'] ?? null) ? array_values($z['brackets']) : [];
            if ($bs === [] || \count($bs) > 12) {
                $err["zones.$i.brackets"] = 'De 1 à 12 tranches de poids.';
            }
            foreach (array_slice($bs, 0, 12) as $j => $b) {
                $up = $b['upToG'] ?? null;
                $price = $b['price'] ?? null;
                if (!is_numeric($up) || (int) $up != $up || $up <= $prev || $up > 30000) {
                    $err["zones.$i.brackets.$j.upToG"] = 'Poids croissant, en grammes (30 000 maximum).';
                } elseif (!is_numeric($price) || $price < 0 || $price > 500) {
                    $err["zones.$i.brackets.$j.price"] = 'Tarif de 0 à 500 €.';
                } else {
                    $prev = (int) $up;
                    $brackets[] = ['upToG' => (int) $up, 'price' => round((float) $price, 2)];
                }
            }
            $zones[] = ['name' => $name, 'countries' => $countries, 'freeFrom' => $free, 'brackets' => $brackets];
        }
        if ($out['enabled'] && $zones === []) {
            $err['zones'] = 'Créez au moins une zone pour activer le calcul.';
        }
        if ($err !== []) {
            throw new ValidationFailedException($err);
        }
        $out['zones'] = $zones;

        $setting = $this->em->find(Setting::class, self::KEY) ?? new Setting(self::KEY);
        $setting->setValue($out);
        $this->em->persist($setting);
        $this->em->flush();

        return $this->get();
    }
}
