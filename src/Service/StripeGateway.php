<?php

namespace App\Service;

use App\Entity\Money;
use App\Entity\Order;
use Stripe\Event;
use Stripe\StripeClient;
use Stripe\Webhook;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Seul point de contact avec Stripe (le reste de l'application ne connaît pas le SDK).
// Paiement par Stripe Checkout (page de paiement hébergée par Stripe) : aucune donnée bancaire ne passe par ce site ni par l'API.
final class StripeGateway
{
    // Montant minimum accepté par Stripe pour un paiement en euros
    public const MIN_CENTS = 50;

    // Adresses du site React après le paiement, par langue (miroir de src/i18n/routes.js côté React)
    private const RETURN_PATHS = [
        'fr' => ['success' => '/commande', 'cancel' => '/panier'],
        'en' => ['success' => '/en/order', 'cancel' => '/en/cart'],
    ];

    private ?StripeClient $client = null;

    public function __construct(
        #[Autowire('%env(STRIPE_SECRET_KEY)%')] private readonly string $secretKey,
        #[Autowire('%env(STRIPE_WEBHOOK_SECRET)%')] private readonly string $webhookSecret,
        #[Autowire('%env(STRIPE_SHIPPING_COUNTRIES)%')] private readonly string $shippingCountries,
        #[Autowire('%env(STRIPE_SHIPPING_RATE_ID)%')] private readonly string $shippingRateId,
        #[Autowire('%env(FRONTEND_URL)%')] private readonly string $frontendUrl,
    ) {
    }

    /** Sans clé secrète, le paiement en ligne est désactivé : les commandes sont enregistrées comme avant. */
    public function isEnabled(): bool
    {
        return trim($this->secretKey) !== '';
    }

    public function hasWebhookSecret(): bool
    {
        return trim($this->webhookSecret) !== '';
    }

    /**
     * Crée la page de paiement. Les montants viennent de la commande (donc de la base), jamais du navigateur.
     *
     * @return array{id: string, url: string}
     */
    public function createCheckoutSession(Order $order, string $locale): array
    {
        $lang = $locale === 'en' ? 'en' : 'fr';
        $base = rtrim($this->frontendUrl, '/');

        $lines = [];
        foreach ($order->getItems() as $item) {
            $lines[] = [
                'quantity' => (int) $item['qty'],
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => Money::toCents($item['price']),
                    'product_data' => ['name' => (string) $item['name']],
                ],
            ];
        }

        $countries = array_values(array_filter(array_map(
            static fn (string $c) => strtoupper(trim($c)),
            explode(',', $this->shippingCountries),
        )));

        $params = [
            'mode' => 'payment',
            'client_reference_id' => $order->getReference(),
            'customer_email' => $order->getCustomerEmail(),
            'locale' => $lang,
            'line_items' => $lines,
            // {CHECKOUT_SESSION_ID} est remplacé par Stripe (ne pas l'encoder)
            'success_url' => $base.self::RETURN_PATHS[$lang]['success'].'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $base.self::RETURN_PATHS[$lang]['cancel'].'?cancelled=1',
            // Durée de vie de la page de paiement : 31 minutes (minimum accepté par Stripe : 30). Ensuite la commande est annulée.
            'expires_at' => time() + 31 * 60,
            'billing_address_collection' => 'auto',
            'metadata' => ['order_ref' => $order->getReference(), 'order_id' => (string) $order->getId()],
            'payment_intent_data' => [
                'description' => 'Les galets d’Ambre – '.$order->getReference(),
                'metadata' => ['order_ref' => $order->getReference(), 'order_id' => (string) $order->getId()],
            ],
        ];
        if ($countries !== []) {
            // Produits physiques : Stripe demande l'adresse de livraison (renvoyée ensuite dans le webhook)
            $params['shipping_address_collection'] = ['allowed_countries' => $countries];
        }
        if (trim($this->shippingRateId) !== '') {
            $params['shipping_options'] = [['shipping_rate' => trim($this->shippingRateId)]];
        }

        // La clé d'idempotence évite de créer deux pages de paiement si la requête est rejouée
        $session = $this->client()->checkout->sessions->create($params, ['idempotency_key' => 'checkout-order-'.$order->getId()]);

        return ['id' => (string) $session->id, 'url' => (string) $session->url];
    }

    /** Session Checkout telle que Stripe la connaît (pour rattraper un webhook manqué). */
    public function retrieveSession(string $sessionId): array
    {
        return $this->client()->checkout->sessions->retrieve($sessionId)->toArray();
    }

    /** Remboursement total. */
    public function refund(Order $order): void
    {
        $this->client()->refunds->create(
            ['payment_intent' => $order->getPaymentIntentId(), 'metadata' => ['order_ref' => $order->getReference()]],
            ['idempotency_key' => 'refund-order-'.$order->getId()],
        );
    }

    /**
     * Vérifie la signature du webhook (preuve que l'appel vient bien de Stripe) et renvoie l'événement.
     *
     * @throws \UnexpectedValueException                          corps illisible
     * @throws \Stripe\Exception\SignatureVerificationException   signature invalide
     */
    public function parseWebhook(string $payload, string $signatureHeader): Event
    {
        return Webhook::constructEvent($payload, $signatureHeader, $this->webhookSecret);
    }

    private function client(): StripeClient
    {
        return $this->client ??= new StripeClient($this->secretKey);
    }
}
