<?php

namespace App\Controller;

use App\Repository\OrderRepository;
use App\Service\PaymentService;
use App\Service\StripeGateway;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/payments')]
final class PaymentController extends ApiController
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly PaymentService $payments,
        private readonly StripeGateway $stripe,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.order_status')] private readonly RateLimiterFactory $statusLimiter,
    ) {
    }

    // Page de retour du React (/commande?session_id=cs_…) : où en est le paiement ?
    // L'identifiant de session Stripe, long et imprévisible, sert de « clé » : seule la personne revenue de Stripe le connaît.
    // Si le webhook n'est pas encore arrivé, on interroge Stripe directement (et on valide la commande si le paiement est confirmé).
    #[Route('/checkout/{sessionId}', name: 'api_payment_checkout', requirements: ['sessionId' => 'cs_[A-Za-z0-9_]+'], methods: ['GET'])]
    public function checkout(string $sessionId, Request $request): JsonResponse
    {
        $this->throttle($this->statusLimiter, $request->getClientIp() ?? 'unknown');

        $order = $this->orders->findOneBy(['stripeSessionId' => $sessionId]) ?? throw new NotFoundHttpException();
        $this->payments->sync($order);

        return new JsonResponse([
            'id' => $order->getReference(),
            'status' => $order->getStatus()->value,
            'paid' => $order->getPaidAt() !== null && $order->getRefundedAt() === null,
            'refunded' => $order->getRefundedAt() !== null,
            'total' => $order->toArray()['total'],
            'customer' => ['email' => $order->getCustomerEmail()],
        ]);
    }

    // Appelé par Stripe (serveur à serveur) : pas de jeton de connexion, la SIGNATURE fait la preuve.
    // URL à déclarer dans Stripe > Développeurs > Webhooks, événements : checkout.session.completed, checkout.session.expired,
    // checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, charge.refunded
    #[Route('/webhook', name: 'api_payment_webhook', methods: ['POST'])]
    public function webhook(Request $request): JsonResponse
    {
        if (!$this->stripe->isEnabled() || !$this->stripe->hasWebhookSecret()) {
            return new JsonResponse(['error' => 'webhook_not_configured', 'message' => 'Webhook Stripe non configuré.'], 503);
        }

        try {
            $event = $this->stripe->parseWebhook($request->getContent(), (string) $request->headers->get('Stripe-Signature', ''));
        } catch (\UnexpectedValueException|\Stripe\Exception\SignatureVerificationException $e) {
            $this->logger->warning('Webhook Stripe refusé : '.$e->getMessage());

            return new JsonResponse(['error' => 'invalid_signature', 'message' => 'Signature invalide.'], 400);
        }

        // Une erreur ici renvoie 500 : Stripe réessaiera plus tard (le traitement est idempotent)
        $this->payments->handleEvent($event);

        return new JsonResponse(['received' => true]);
    }
}
