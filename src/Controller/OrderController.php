<?php

namespace App\Controller;

use App\Dto\OrderInput;
use App\Entity\Order;
use App\Entity\OrderStatus;
use App\Entity\User;
use App\Repository\OrderRepository;
use App\Service\ApiValidator;
use App\Service\AppMailer;
use App\Service\InvoiceService;
use App\Service\StockService;
use App\Service\TrackingLinks;
use App\Service\OrderService;
use App\Service\PaymentService;
use App\Service\ValidationFailedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
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
        private readonly InvoiceService $invoices,
        private readonly StockService $stock,
        private readonly AppMailer $mailer,
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

    // { "status": "expédiée", "tracking": { "carrier": "Colissimo", "number": "6A12345678901", "url": "https://…" (facultatif) }, "notify": true }
    // « expédiée » : enregistre le suivi et prévient le client par e-mail (notify=false pour ne pas l'envoyer).
    // « annulée » avant envoi : les pièces redeviennent disponibles.
    #[Route('/{ref}/status', name: 'api_orders_status', requirements: ['ref' => 'CMD-\d+'], methods: ['PATCH', 'PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function status(string $ref, Request $request): JsonResponse
    {
        $order = $this->find($ref);
        $body = $this->body($request);
        $value = $body['status'] ?? null;
        $status = is_string($value) ? OrderStatus::tryFrom($value) : null;
        if ($status === null) {
            throw new ValidationFailedException(['status' => 'Statut invalide.']);
        }
        $tracking = $status === OrderStatus::Shipped ? $this->tracking($body['tracking'] ?? null) : null;

        $previous = $order->getStatus();
        $order->setStatus($status);
        if ($status === OrderStatus::Shipped) {
            if ($previous !== OrderStatus::Shipped) {
                $order->setShippedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            }
            if ($tracking !== null) {
                $order->setTracking($tracking['carrier'], $tracking['number'], $tracking['url']);
            }
        }
        $this->em->flush();

        if ($status === OrderStatus::Cancelled && \in_array($previous, [OrderStatus::Pending, OrderStatus::Paid], true)) {
            $this->stock->release($order);
        }
        $notify = filter_var($body['notify'] ?? true, \FILTER_VALIDATE_BOOLEAN);
        if ($status === OrderStatus::Shipped && $notify && ($previous !== OrderStatus::Shipped || $tracking !== null)) {
            $this->mailer->sendShipped($order);
        }

        return new JsonResponse($order->toArray());
    }

    /** @return array{carrier: ?string, number: string, url: ?string}|null null si aucun suivi n'est fourni */
    private function tracking(mixed $raw): ?array
    {
        if (!\is_array($raw)) {
            return null;
        }
        $carrier = \is_string($raw['carrier'] ?? null) ? trim($raw['carrier']) : '';
        $number = \is_string($raw['number'] ?? null) ? trim($raw['number']) : '';
        $url = \is_string($raw['url'] ?? null) ? trim($raw['url']) : '';
        if ($carrier === '' && $number === '' && $url === '') {
            return null;
        }
        $errors = [];
        if (mb_strlen($carrier) > 40) {
            $errors['tracking.carrier'] = 'Nom du transporteur trop long (40 caractères maximum).';
        }
        if ($number === '' || !preg_match('~^[A-Za-z0-9][A-Za-z0-9 ._/-]{0,59}$~', $number)) {
            $errors['tracking.number'] = 'Numéro de suivi invalide (lettres, chiffres, 60 caractères maximum).';
        }
        // Le lien est repris dans l'e-mail du client : uniquement http(s)
        if ($url !== '' && (mb_strlen($url) > 500 || !filter_var($url, \FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $url))) {
            $errors['tracking.url'] = 'Lien de suivi invalide (adresse complète en http:// ou https://).';
        }
        if ($errors !== []) {
            throw new ValidationFailedException($errors);
        }

        return ['carrier' => $carrier !== '' ? $carrier : null, 'number' => $number, 'url' => $url !== '' ? $url : TrackingLinks::urlFor($carrier, $number)];
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

    // Facture PDF d'une commande payée : réservée à la personne concernée (compte lié, ou même adresse e-mail vérifiée) et aux administrateurs.
    // Une commande qui n'est pas la sienne répond « introuvable » (on ne révèle pas son existence).
    #[Route('/{ref}/invoice', name: 'api_orders_invoice', requirements: ['ref' => 'CMD-\d+'], methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function invoice(string $ref): Response
    {
        $order = $this->find($ref);
        $user = $this->getUser();
        $owner = $user instanceof User && (
            $order->getUser()?->getId() === $user->getId()
            || ($user->isVerified() && mb_strtolower($order->getCustomerEmail()) === mb_strtolower($user->getEmail()))
        );
        if (!$owner && !$this->isGranted('ROLE_ADMIN')) {
            throw new NotFoundHttpException();
        }
        if (!$order->isInvoiceable()) {
            throw new ConflictHttpException('Aucune facture n’est disponible pour cette commande (non payée, annulée ou remboursée).');
        }

        $pdf = $this->invoices->render($order);

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $this->invoices->fileName($order)),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function find(string $ref): Order
    {
        $id = (int) substr($ref, 4) - Order::REF_OFFSET;

        return ($id > 0 ? $this->orders->find($id) : null) ?? throw new NotFoundHttpException();
    }
}
