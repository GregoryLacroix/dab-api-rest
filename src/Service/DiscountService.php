<?php

namespace App\Service;

use App\Entity\GiftCard;
use App\Entity\Order;
use App\Entity\PromoCode;
use App\Repository\GiftCardRepository;
use App\Repository\OrderRepository;
use App\Repository\PromoCodeRepository;

// Codes promo et cartes cadeau : calcul de la remise d'un panier, réservation à la commande, restitution à l'annulation.
// Les remises ne portent que sur les articles (jamais sur la livraison) et laissent toujours au moins 0,50 € à payer en ligne.
final class DiscountService
{
    public function __construct(
        private readonly PromoCodeRepository $promos,
        private readonly GiftCardRepository $gifts,
        private readonly OrderRepository $orders,
        private readonly StripeGateway $stripe,
    ) {
    }

    /**
     * Évalue des codes saisis par le client pour un total d'articles. Lève une ValidationFailedException (champ « code ») si un code est refusé.
     *
     * @param string[] $codes
     * @return array{promo: ?PromoCode, promoCents: int, gift: ?GiftCard, giftCents: int}
     */
    public function evaluate(array $codes, int $itemsCents, string $email = ''): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $promo = null;
        $gift = null;
        foreach ($codes as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            $normalized = PromoCode::normalize($raw);
            if (str_starts_with($normalized, 'GDA-') && ($card = $this->gifts->findByCode($normalized)) !== null) {
                if ($gift !== null) {
                    throw new ValidationFailedException(['code' => 'Une seule carte cadeau peut être utilisée par commande.']);
                }
                if (($problem = $card->problem($now)) !== null) {
                    throw new ValidationFailedException(['code' => $problem]);
                }
                $gift = $card;
                continue;
            }
            $found = $this->promos->findByCode($normalized);
            if ($found === null) {
                throw new ValidationFailedException(['code' => 'Ce code n’existe pas.']);
            }
            if ($promo !== null) {
                throw new ValidationFailedException(['code' => 'Un seul code promo peut être utilisé par commande.']);
            }
            if (($problem = $found->problem($itemsCents, $now)) !== null) {
                throw new ValidationFailedException(['code' => $problem]);
            }
            if ($found->isOnePerCustomer() && $email !== '' && $this->orders->countWithPromo($found->getCode(), $email) > 0) {
                throw new ValidationFailedException(['code' => 'Ce code a déjà été utilisé avec cette adresse e-mail.']);
            }
            $promo = $found;
        }

        // Plafond : il doit rester de quoi payer (minimum Stripe) quand le paiement en ligne est actif
        $room = max(0, $itemsCents - ($this->stripe->isEnabled() ? StripeGateway::MIN_CENTS : 0));
        $promoCents = $promo === null ? 0 : min($promo->discountFor($itemsCents), $room);
        $giftCents = $gift === null ? 0 : min($gift->getBalanceCents(), $room - $promoCents);

        return ['promo' => $promo, 'promoCents' => $promoCents, 'gift' => $gift, 'giftCents' => max(0, $giftCents)];
    }

    /**
     * Consomme les codes (à appeler dans la transaction de création de la commande) : l'usage du code promo et le solde de la carte
     * sont pris de façon atomique, donc jamais utilisés deux fois par deux commandes simultanées.
     *
     * @param array{promo: ?PromoCode, promoCents: int, gift: ?GiftCard, giftCents: int} $applied
     */
    public function consume(array $applied): void
    {
        if ($applied['promo'] !== null && !$this->promos->consume($applied['promo'])) {
            throw new ValidationFailedException(['code' => 'Ce code a atteint son nombre maximal d’utilisations.']);
        }
        if ($applied['gift'] !== null && $applied['giftCents'] > 0 && !$this->gifts->debit($applied['gift'], $applied['giftCents'])) {
            throw new ValidationFailedException(['code' => 'Le solde de la carte cadeau a changé : actualisez le panier.']);
        }
    }

    /** Rend le code promo et le solde de la carte quand la commande est annulée ou remboursée (une seule fois par commande). */
    public function release(Order $order): void
    {
        if (!$this->orders->claimDiscountRelease($order)) {
            return;
        }
        if ($order->getPromoCode() !== null) {
            $this->promos->restore($order->getPromoCode());
        }
        if ($order->getGiftCode() !== null && $order->getGiftCents() > 0) {
            $this->gifts->credit($order->getGiftCode(), $order->getGiftCents());
        }
    }
}
