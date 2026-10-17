<?php

namespace App\Controller;

use App\Dto\OrderInput;
use App\Entity\Order;
use App\Entity\OrderStatus;
use App\Entity\User;
use App\Repository\OrderRepository;
use App\Service\ApiValidator;
use App\Service\OrderService;
use App\Service\PaymentService;
use App\Service\ValidationFailedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/orders')]
final class OrderController extends ApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrderRepository $orders,
        private readonly OrderService $service,
        private readonly PaymentService $payments,
        private readonly ApiValidator $validator,
        #[Autowire(service: 'limiter.order')] private readonly RateLimiterFactory $orderLimiter,
    ) {
    }

    // Panier "Commander" (public, comme le React : nom + e-mail) : { customer: { name, email }, items: [{ productId, qty }], acceptTerms: true }
    // Réponse 201 : la commande + `checkoutUrl` (page de paiement Stripe vers laquelle rediriger le navigateur ; null si paiement en ligne désactivé)
    #[Route('', name: 'api_orders_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->throttle($this->orderLimiter, $request->getClientIp() ?? 'unknown');

        $input = OrderInput::fromArray($this->body($request));
        $this->validator->check($input);
        $user = $this->getUser();
        // La langue de la page de paiement suit celle du site (le React envoie Accept-Language : fr ou en)
        $locale = $request->getPreferredLanguage(['fr', 'en']) ?? 'fr';
        $placed = $this->service->create($input, $user instanceof User ? $user : null, $locale);

        return new JsonResponse($placed->order->toArray() + ['checkoutUrl' => $placed->checkoutUrl], 201);
    }

    // Back-office "Commandes" : ?status=payée &q=martin &page=1 &perPage=10
    #[Route('', name: 'api_orders_index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(Request $request): JsonResponse
    {
        $status = null;
        $raw = $request->query->get('status');
        if ($raw !== null && $raw !== '' && $raw !== 'toutes') {
            $status = OrderStatus::tryFrom((string) $raw)
                ?? throw new ValidationFailedException(['status' => 'Statut invalide.']);
        }

        return $this->paginate($this->orders->search($status, $request->query->get('q')), $request, fn (Order $o) => $o->toArray(), 10);
    }

    #[Route('/{ref}', name: 'api_orders_show', requirements: ['ref' => 'CMD-\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function show(string $ref): JsonResponse
    {
        return new JsonResponse($this->find($ref)->toArray());
    }

    // { "status": "expédiée" }
    #[Route('/{ref}/status', name: 'api_orders_status', requirements: ['ref' => 'CMD-\d+'], methods: ['PATCH', 'PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function status(string $ref, Request $request): JsonResponse
    {
        $order = $this->find($ref);
        $value = $this->body($request)['status'] ?? null;
        $status = is_string($value) ? OrderStatus::tryFrom($value) : null;
        if ($status === null) {
            throw new ValidationFailedException(['status' => 'Statut invalide.']);
        }
        $order->setStatus($status);
        $this->em->flush();

        return new JsonResponse($order->toArray());
    }

    // Remboursement total d'une commande payée en ligne (puis statut « annulée »)
    #[Route('/{ref}/refund', name: 'api_orders_refund', requirements: ['ref' => 'CMD-\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function refund(string $ref): JsonResponse
    {
        $order = $this->find($ref);
        $this->payments->refund($order);

        return new JsonResponse($order->toArray());
    }

    private function find(string $ref): Order
    {
        $id = (int) substr($ref, 4) - Order::REF_OFFSET;

        return ($id > 0 ? $this->orders->find($id) : null) ?? throw new NotFoundHttpException();
    }
}
