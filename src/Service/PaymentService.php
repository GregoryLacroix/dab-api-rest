<?php

namespace App\Service;

use App\Entity\Order;
use App\Entity\OrderStatus;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Event;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Cycle de vie du paiement. Deux chemins mènent aux mêmes méthodes, et peuvent se croiser sans danger :
//   1. le webhook envoyé par Stripe (source de vérité) ;
//   2. la page de retour (ou la commande app:sync-payments) qui interroge Stripe si le webhook tarde ou n'est pas branché.
// Le passage « en attente » -> « payée » est atomique (OrderRepository::transition) : un seul des deux chemins l'emporte.
final class PaymentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrderRepository $orders,
        private readonly StripeGateway $stripe,
        private readonly AppMailer $mailer,
        private readonly InvoiceService $invoices,
        private readonly StockService $stock,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(CONTACT_EMAIL)%')] private readonly string $shopEmail,
    ) {
    }

    /** Événement reçu de Stripe (signature déjà vérifiée). Les événements inconnus sont ignorés. */
    public function handleEvent(Event $event): void
    {
        $object = $event->data->object->toArray();

        switch ($event->type) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
            case 'checkout.session.expired':
                $order = $this->orderForSession($object);
                if ($order !== null) {
                    $this->applySession($order, $object);
                }
                break;

            case 'checkout.session.async_payment_failed': // virement / prélèvement refusé après coup
                $order = $this->orderForSession($object);
                if ($order !== null) {
                    $this->cancelUnpaid($order);
                }
                break;

            case 'charge.refunded': // remboursement fait depuis le tableau de bord Stripe
                $this->applyRefund($object);
                break;
        }
    }

    /** Rattrape un webhook manqué : demande à Stripe où en est la session d'une commande encore « en attente ». */
    public function sync(Order $order): void
    {
        if (!$this->stripe->isEnabled() || $order->getStripeSessionId() === null || $order->getStatus() !== OrderStatus::Pending) {
            return;
        }
        try {
            $session = $this->stripe->retrieveSession($order->getStripeSessionId());
        } catch (\Throwable $e) {
            $this->logger->warning('Stripe : session illisible ('.$e->getMessage().')', ['order' => $order->getReference()]);

            return;
        }
        $this->applySession($order, $session);
    }

    /** Remboursement total depuis le back-office : rembourse chez Stripe, puis passe la commande à « annulée ». */
    public function refund(Order $order): void
    {
        if ($order->getPaymentIntentId() === null || $order->getPaidAt() === null) {
            throw new ConflictHttpException('Cette commande n’a pas été payée en ligne : il n’y a rien à rembourser ici.');
        }
        if ($order->getRefundedAt() !== null) {
            throw new ConflictHttpException('Cette commande est déjà remboursée.');
        }
        try {
            $this->stripe->refund($order);
        } catch (\Throwable $e) {
            $this->logger->error('Stripe : remboursement impossible ('.$e->getMessage().')', ['order' => $order->getReference()]);
            throw new HttpException(502, 'Stripe a refusé le remboursement : '.$e->getMessage());
        }
        $this->markRefunded($order);
    }

    // ---------------------------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $session */
    private function applySession(Order $order, array $session): void
    {
        $state = $session['status'] ?? null;
        $payment = $session['payment_status'] ?? null;

        if ($state === 'complete' && \in_array($payment, ['paid', 'no_payment_required'], true)) {
            $this->markPaid($order, $session);
        } elseif ($state === 'expired') {
            $this->cancelUnpaid($order);
        }
        // complete + unpaid = paiement différé (virement…) : on attend l'événement async_payment_succeeded
    }

    /** @param array<string, mixed> $session */
    private function markPaid(Order $order, array $session): void
    {
        if (!$this->orders->transition($order, OrderStatus::Pending, OrderStatus::Paid)) {
            if ($order->getPaidAt() === null && $order->getStatus() === OrderStatus::Cancelled) {
                // Argent encaissé pour une commande annulée (annulation manuelle pendant le paiement ?) : à traiter à la main
                $this->logger->error('Stripe : paiement reçu pour une commande annulée, à vérifier.', ['order' => $order->getReference(), 'session' => $session['id'] ?? null]);
            }

            return; // déjà traitée par l'autre chemin (webhook ou page de retour)
        }

        $this->em->refresh($order);
        $intent = $session['payment_intent'] ?? null;
        $order->setPaidAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setPaymentIntentId(\is_array($intent) ? ($intent['id'] ?? null) : (\is_string($intent) ? $intent : null))
            ->setShippingAddress($this->shippingFrom($session));
        // Le total encaissé par Stripe fait foi (il inclut les éventuels frais de port)
        $paid = $session['amount_total'] ?? null;
        if (\is_int($paid) && $paid > 0) {
            $order->setTotalCents($paid);
        }
        $this->em->flush();
        $this->invoices->ensureNumber($order); // numéro de facture attribué au moment du paiement (ordre chronologique)

        $this->mailer->sendOrderConfirmation($order);
        $this->mailer->sendNewOrderNotification($order, $this->shopEmail);
    }

    private function cancelUnpaid(Order $order): void
    {
        if ($this->orders->transition($order, OrderStatus::Pending, OrderStatus::Cancelled)) {
            $this->em->refresh($order);
            $this->stock->release($order); // paiement expiré, refusé ou abandonné : les pièces redeviennent disponibles
        }
    }

    /** @param array<string, mixed> $charge */
    private function applyRefund(array $charge): void
    {
        $intent = $charge['payment_intent'] ?? null;
        $order = \is_string($intent) ? $this->orders->findOneBy(['paymentIntentId' => $intent]) : null;
        if ($order === null) {
            return;
        }
        if (($charge['refunded'] ?? false) !== true) {
            $this->logger->info('Stripe : remboursement partiel, la commande garde son statut.', ['order' => $order->getReference()]);

            return;
        }
        $this->markRefunded($order);
    }

    private function markRefunded(Order $order): void
    {
        $first = $order->getRefundedAt() === null;
        $shipped = \in_array($order->getStatus(), [OrderStatus::Shipped, OrderStatus::Delivered], true);
        if ($first) {
            $order->setRefundedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        }
        $order->setStatus(OrderStatus::Cancelled);
        $this->em->flush();

        if ($first) {
            if (!$shipped) {
                $this->stock->release($order); // colis pas encore parti : la pièce est de nouveau en vente
            }
            $this->mailer->sendRefund($order);
        }
    }

    /** @param array<string, mixed> $session */
    private function orderForSession(array $session): ?Order
    {
        $id = $session['id'] ?? null;
        $order = \is_string($id) ? $this->orders->findOneBy(['stripeSessionId' => $id]) : null;
        if ($order === null) {
            $this->logger->warning('Stripe : événement pour une session inconnue.', ['session' => $id]);
        }

        return $order;
    }

    /**
     * Adresse de livraison saisie chez Stripe. Selon la version de l'API Stripe, elle se trouve dans
     * collected_information.shipping_details (récent) ou shipping_details (ancien).
     *
     * @param array<string, mixed> $session
     * @return array<string, ?string>|null
     */
    private function shippingFrom(array $session): ?array
    {
        $details = $session['collected_information']['shipping_details'] ?? $session['shipping_details'] ?? null;
        $address = \is_array($details) ? ($details['address'] ?? null) : null;
        if (!\is_array($address)) {
            return null;
        }

        return [
            'name' => $details['name'] ?? null,
            'line1' => $address['line1'] ?? null,
            'line2' => $address['line2'] ?? null,
            'postalCode' => $address['postal_code'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'country' => $address['country'] ?? null,
        ];
    }
}
