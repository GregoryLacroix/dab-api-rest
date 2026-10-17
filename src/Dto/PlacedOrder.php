<?php

namespace App\Dto;

use App\Entity\Order;

// Résultat de la création d'une commande : la commande, et l'adresse de la page de paiement Stripe (null si paiement en ligne désactivé)
final class PlacedOrder
{
    public function __construct(
        public readonly Order $order,
        public readonly ?string $checkoutUrl = null,
    ) {
    }
}
