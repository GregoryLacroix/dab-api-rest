<?php

namespace App\Controller;

use App\Entity\GiftCard;
use App\Entity\Money;
use App\Entity\PromoCode;
use App\Repository\GiftCardRepository;
use App\Repository\ProductRepository;
use App\Repository\PromoCodeRepository;
use App\Service\DiscountService;
use App\Service\GiftCardService;
use App\Service\ValidationFailedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Codes promo et cartes cadeau : vérification au panier, achat d'une carte, gestion dans le back-office.
#[Route('/api')]
final class DiscountController extends ApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PromoCodeRepository $promos,
        private readonly GiftCardRepository $gifts,
        private readonly ProductRepository $products,
        private readonly DiscountService $discounts,
        private readonly GiftCardService $giftCards,
        #[Autowire(service: 'limiter.codes')] private readonly RateLimiterFactory $codesLimiter,
        #[Autowire(service: 'limiter.gift_card')] private readonly RateLimiterFactory $giftLimiter,
        #[Autowire(service: 'limiter.order_status')] private readonly RateLimiterFactory $statusLimiter,
    ) {
    }

    // ---------- Public ----------------------------------------------------------------------------------------------------

    // Panier : { codes: ["BIENVENUE10", "GDA-…"], items: [{ productId, qty }], email? } -> remise calculée par le serveur
    #[Route('/codes/check', name: 'api_codes_check', methods: ['POST'])]
    public function check(Request $request): JsonResponse
    {
        $this->throttle($this->codesLimiter, $request->getClientIp() ?? 'unknown');
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
        $codes = array_values(array_filter(\is_array($body['codes'] ?? null) ? array_slice($body['codes'], 0, 2) : [], 'is_string'));
        $email = \is_string($body['email'] ?? null) ? mb_strtolower(trim($body['email'])) : '';

        $r = $this->discounts->evaluate($codes, $items, $email);

        return new JsonResponse([
            'promo' => $r['promo'] === null ? null : ['code' => $r['promo']->getCode(), 'kind' => $r['promo']->getKind(), 'amount' => Money::fromCents($r['promoCents'])],
            'gift' => $r['gift'] === null ? null : ['code' => $r['gift']->getCode(), 'amount' => Money::fromCents($r['giftCents']), 'balance' => Money::fromCents($r['gift']->getBalanceCents())],
            'discount' => Money::fromCents($r['promoCents'] + $r['giftCents']),
        ]);
    }

    // Achat d'une carte cadeau : { amount (euros), buyer: { name, email }, recipient?: { name, email }, message?, acceptTerms }
    // Réponse 201 : { checkoutUrl } (page de paiement Stripe)
    #[Route('/gift-cards', name: 'api_gift_cards_buy', methods: ['POST'])]
    public function buy(Request $request): JsonResponse
    {
        $this->throttle($this->giftLimiter, $request->getClientIp() ?? 'unknown');
        $b = $this->body($request);
        $err = [];

        $cents = \is_int($b['amount'] ?? null) || \is_float($b['amount'] ?? null) ? Money::toCents($b['amount']) : 0;
        if ($cents < GiftCard::MIN_CENTS || $cents > GiftCard::MAX_CENTS) {
            $err['amount'] = 'Choisissez un montant entre 10 € et 500 €.';
        }
        $buyer = \is_array($b['buyer'] ?? null) ? $b['buyer'] : [];
        $name = \is_string($buyer['name'] ?? null) ? trim($buyer['name']) : '';
        $email = \is_string($buyer['email'] ?? null) ? mb_strtolower(trim($buyer['email'])) : '';
        if ($name === '' || mb_strlen($name) > 120) {
            $err['buyer.name'] = 'Saisissez votre nom.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || \strlen($email) > 180) {
            $err['buyer.email'] = 'Saisissez une adresse e-mail valide.';
        }
        $rec = \is_array($b['recipient'] ?? null) ? $b['recipient'] : [];
        $rName = \is_string($rec['name'] ?? null) && trim($rec['name']) !== '' ? trim($rec['name']) : null;
        $rEmail = \is_string($rec['email'] ?? null) && trim($rec['email']) !== '' ? mb_strtolower(trim($rec['email'])) : null;
        if ($rEmail !== null && (!filter_var($rEmail, FILTER_VALIDATE_EMAIL) || \strlen($rEmail) > 180)) {
            $err['recipient.email'] = 'Adresse e-mail du destinataire invalide.';
        }
        if ($rName !== null && mb_strlen($rName) > 120) {
            $err['recipient.name'] = 'Nom trop long.';
        }
        $message = \is_string($b['message'] ?? null) && trim($b['message']) !== '' ? trim($b['message']) : null;
        if ($message !== null && mb_strlen($message) > 500) {
            $err['message'] = 'Message trop long (500 caractères maximum).';
        }
        if (!filter_var($b['acceptTerms'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $err['acceptTerms'] = 'Veuillez accepter les conditions générales de vente.';
        }
        if ($err !== []) {
            throw new ValidationFailedException($err);
        }

        $lang = $request->getPreferredLanguage(['fr', 'en']) ?? 'fr';
        $r = $this->giftCards->purchase([
            'amount' => $cents, 'buyerName' => $name, 'buyerEmail' => $email,
            'recipientName' => $rName, 'recipientEmail' => $rEmail, 'message' => $message,
        ], $lang);

        return new JsonResponse(['checkoutUrl' => $r['checkoutUrl']], 201);
    }

    // Page de retour de Stripe : où en est l'achat ? (l'identifiant de session, imprévisible, sert de clé)
    #[Route('/gift-cards/checkout/{sessionId}', name: 'api_gift_cards_checkout', requirements: ['sessionId' => 'cs_[A-Za-z0-9_]+'], methods: ['GET'])]
    public function checkout(string $sessionId, Request $request): JsonResponse
    {
        $this->throttle($this->statusLimiter, $request->getClientIp() ?? 'unknown');
        $card = $this->gifts->findOneBy(['stripeSessionId' => $sessionId]) ?? throw new NotFoundHttpException();
        $this->giftCards->sync($card);

        return new JsonResponse([
            'paid' => $card->getStatus() === GiftCard::ACTIVE,
            'amount' => Money::fromCents($card->getInitialCents()),
            'buyerEmail' => $card->getBuyerEmail(),
            'recipientEmail' => $card->getRecipientEmail(),
        ]);
    }

    // ---------- Back-office : codes promo ---------------------------------------------------------------------------------

    #[Route('/admin/promo-codes', name: 'api_promo_index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function promoIndex(Request $request): JsonResponse
    {
        return $this->paginate($this->promos->search($request->query->get('q')), $request, fn (PromoCode $p) => $p->toArray(), 20);
    }

    #[Route('/admin/promo-codes', name: 'api_promo_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function promoCreate(Request $request): JsonResponse
    {
        $promo = new PromoCode();
        $this->fillPromo($promo, $this->body($request), true);
        $this->em->persist($promo);
        $this->em->flush();

        return new JsonResponse($promo->toArray(), 201);
    }

    #[Route('/admin/promo-codes/{id}', name: 'api_promo_update', requirements: ['id' => '\d+'], methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function promoUpdate(int $id, Request $request): JsonResponse
    {
        $promo = $this->promos->find($id) ?? throw new NotFoundHttpException();
        $this->fillPromo($promo, $this->body($request), false);
        $this->em->flush();

        return new JsonResponse($promo->toArray());
    }

    #[Route('/admin/promo-codes/{id}', name: 'api_promo_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function promoDelete(int $id): Response
    {
        $promo = $this->promos->find($id) ?? throw new NotFoundHttpException();
        // Les commandes passées gardent le code en clair : on peut supprimer sans rien casser
        $this->em->remove($promo);
        $this->em->flush();

        return new Response('', 204);
    }

    /** @param array<string, mixed> $b */
    private function fillPromo(PromoCode $p, array $b, bool $creating): void
    {
        $err = [];
        if ($creating || \array_key_exists('code', $b)) {
            $code = PromoCode::normalize(\is_string($b['code'] ?? null) ? $b['code'] : '');
            if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) {
                $err['code'] = 'Le code doit contenir de 3 à 40 caractères : lettres, chiffres, tiret.';
            } elseif (str_starts_with($code, 'GDA-')) {
                $err['code'] = 'Le préfixe GDA- est réservé aux cartes cadeau.';
            } elseif (($other = $this->promos->findByCode($code)) !== null && $other->getId() !== $p->getId()) {
                $err['code'] = 'Ce code existe déjà.';
            } else {
                $p->setCode($code);
            }
        }
        if ($creating || \array_key_exists('kind', $b) || \array_key_exists('value', $b)) {
            $kind = ($b['kind'] ?? $p->getKind()) === PromoCode::FIXED ? PromoCode::FIXED : PromoCode::PERCENT;
            $raw = $b['value'] ?? ($kind === PromoCode::PERCENT ? $p->getValue() : Money::fromCents($p->getValue()));
            if (!is_numeric($raw)) {
                $err['value'] = 'Saisissez une valeur.';
            } elseif ($kind === PromoCode::PERCENT && ($raw < 1 || $raw > 100 || (int) $raw != $raw)) {
                $err['value'] = 'Le pourcentage doit être un entier de 1 à 100.';
            } elseif ($kind === PromoCode::FIXED && ($raw <= 0 || $raw > 10000)) {
                $err['value'] = 'Le montant doit être compris entre 0,01 € et 10 000 €.';
            } else {
                $p->setKind($kind)->setValue($kind === PromoCode::PERCENT ? (int) $raw : Money::toCents($raw));
            }
        }
        if (\array_key_exists('minOrder', $b)) {
            $v = $b['minOrder'];
            if ($v === null || $v === '') {
                $p->setMinOrderCents(0);
            } elseif (!is_numeric($v) || $v < 0) {
                $err['minOrder'] = 'Montant minimum invalide.';
            } else {
                $p->setMinOrderCents(Money::toCents($v));
            }
        }
        foreach (['startsAt' => 'setStartsAt', 'endsAt' => 'setEndsAt'] as $key => $setter) {
            if (!\array_key_exists($key, $b)) {
                continue;
            }
            $v = $b[$key];
            if ($v === null || $v === '') {
                $p->$setter(null);
                continue;
            }
            try {
                $p->$setter(new \DateTimeImmutable((string) $v, new \DateTimeZone('UTC')));
            } catch (\Exception) {
                $err[$key] = 'Date invalide.';
            }
        }
        if ($p->getStartsAt() !== null && $p->getEndsAt() !== null && $p->getEndsAt() < $p->getStartsAt()) {
            $err['endsAt'] = 'La fin doit suivre le début.';
        }
        if (\array_key_exists('maxUses', $b)) {
            $v = $b['maxUses'];
            if ($v === null || $v === '') {
                $p->setMaxUses(null);
            } elseif (!is_numeric($v) || $v < 1 || $v > 1000000) {
                $err['maxUses'] = 'Nombre d’utilisations invalide.';
            } else {
                $p->setMaxUses((int) $v);
            }
        }
        if (\array_key_exists('onePerCustomer', $b)) {
            $p->setOnePerCustomer((bool) $b['onePerCustomer']);
        }
        if (\array_key_exists('active', $b)) {
            $p->setActive((bool) $b['active']);
        }
        if ($err !== []) {
            throw new ValidationFailedException($err);
        }
    }

    // ---------- Back-office : cartes cadeau ------------------------------------------------------------------------------

    #[Route('/admin/gift-cards', name: 'api_gift_admin_index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function giftIndex(Request $request): JsonResponse
    {
        return $this->paginate($this->gifts->search($request->query->get('q')), $request, fn (GiftCard $g) => $g->toArray(), 20);
    }

    // Annule une carte (fraude, litige…) ou la réactive. { status: "active" | "cancelled" }
    #[Route('/admin/gift-cards/{id}', name: 'api_gift_admin_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function giftUpdate(int $id, Request $request): JsonResponse
    {
        $card = $this->gifts->find($id) ?? throw new NotFoundHttpException();
        if ($card->getStatus() === GiftCard::PENDING) {
            throw new ConflictHttpException('Cette carte n’est pas encore payée.');
        }
        $status = $this->body($request)['status'] ?? null;
        if (!\in_array($status, [GiftCard::ACTIVE, GiftCard::CANCELLED], true)) {
            throw new ValidationFailedException(['status' => 'Statut invalide.']);
        }
        $card->setStatus($status);
        $this->em->flush();

        return new JsonResponse($card->toArray());
    }
}
