<?php

namespace App\Controller;

use App\Entity\Money;
use App\Repository\ProductRepository;
use App\Service\DiscountService;
use App\Service\ShippingService;
use App\Service\ValidationFailedException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Frais de port : informations publiques (pays livrés), devis du panier, réglages du back-office
#[Route('/api')]
final class ShippingController extends ApiController
{
    public function __construct(
        private readonly ShippingService $shipping,
        private readonly ProductRepository $products,
        private readonly DiscountService $discounts,
        #[Autowire(service: 'limiter.shipping_quote')] private readonly RateLimiterFactory $quoteLimiter,
    ) {
    }

    // { enabled, zones: [{ name, countries: ["FR"], freeFrom: 60|null }] }
    #[Route('/shipping', name: 'api_shipping_info', methods: ['GET'])]
    public function info(): JsonResponse
    {
        return new JsonResponse($this->shipping->publicInfo(), 200, ['Cache-Control' => 'public, max-age=60']);
    }

    // { country: "FR", items: [{ productId, qty }], codes?: [] } -> { shipping: 6.5, zone: "France", freeFrom: 60|null }
    #[Route('/shipping/quote', name: 'api_shipping_quote', methods: ['POST'])]
    public function quote(Request $request): JsonResponse
    {
        $this->throttle($this->quoteLimiter, $request->getClientIp() ?? 'unknown');
        $body = $this->body($request);

        $qty = [];
        foreach (\is_array($body['items'] ?? null) ? $body['items'] : [] as $row) {
            if (\is_array($row) && \is_int($row['productId'] ?? null) && \is_int($row['qty'] ?? null) && $row['qty'] > 0 && $row['qty'] <= 99) {
                $qty[$row['productId']] = ($qty[$row['productId']] ?? 0) + $row['qty'];
            }
        }
        $found = $this->products->findPublishedByIds(array_keys($qty));
        $items = 0;
        foreach ($qty as $id => $n) {
            if (isset($found[$id])) {
                $items += $found[$id]->getPriceCents() * $n;
            }
        }
        // Remise éventuelle : elle compte pour le seuil de livraison offerte (un code invalide est simplement ignoré ici)
        $discount = 0;
        $codes = array_values(array_filter(\is_array($body['codes'] ?? null) ? array_slice($body['codes'], 0, 2) : [], 'is_string'));
        if ($codes !== []) {
            try {
                $r = $this->discounts->evaluate($codes, $items);
                $discount = $r['promoCents'] + $r['giftCents'];
            } catch (ValidationFailedException) {
            }
        }

        $country = \is_string($body['country'] ?? null) ? $body['country'] : 'FR';
        $q = $this->shipping->quote($this->shipping->weightOf($qty, $found), $items - $discount, $country);
        $zone = null;
        foreach ($this->shipping->get()['zones'] as $z) {
            if ($z['name'] === $q['zone']) {
                $zone = $z;
            }
        }

        return new JsonResponse([
            'enabled' => $q['zone'] !== null,
            'shipping' => Money::fromCents($q['cents']),
            'zone' => $q['zone'],
            'freeFrom' => $zone['freeFrom'] ?? null,
        ]);
    }

    #[Route('/admin/shipping', name: 'api_shipping_admin_get', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminGet(): JsonResponse
    {
        return new JsonResponse($this->shipping->get());
    }

    #[Route('/admin/shipping', name: 'api_shipping_admin_put', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminPut(Request $request): JsonResponse
    {
        return new JsonResponse($this->shipping->update($this->body($request)));
    }
}
