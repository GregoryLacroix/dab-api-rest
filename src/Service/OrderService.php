<?php

namespace App\Service;

use App\Dto\OrderInput;
use App\Dto\PlacedOrder;
use App\Entity\Money;
use App\Entity\Order;
use App\Entity\OrderStatus;
use App\Entity\User;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class OrderService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductRepository $products,
        private readonly AppMailer $mailer,
        private readonly StripeGateway $stripe,
        private readonly StockService $stock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Crée la commande : noms, prix et total viennent de la base, jamais du client.
     * Avec Stripe : la commande est « en attente » et on renvoie l'adresse de la page de paiement ; elle passe à « payée » quand
     * Stripe confirme (PaymentService). Sans Stripe : comportement d'origine (commande enregistrée + e-mail de confirmation).
     */
    public function create(OrderInput $input, ?User $user, string $locale = 'fr'): PlacedOrder
    {
        // Regroupe les lignes d'un même produit
        $qtyById = [];
        $firstIndex = [];
        foreach ($input->items as $i => $row) {
            $qtyById[$row['productId']] = ($qtyById[$row['productId']] ?? 0) + $row['qty'];
            $firstIndex[$row['productId']] ??= $i;
        }
        $products = $this->products->findPublishedByIds(array_keys($qtyById));

        $items = [];
        $total = 0;
        foreach ($qtyById as $id => $qty) {
            $product = $products[$id] ?? null;
            if ($product === null) {
                throw new ValidationFailedException(["items.{$firstIndex[$id]}.productId" => 'Ce produit n’est plus disponible.']);
            }
            if ($qty > 99) {
                throw new ValidationFailedException(["items.{$firstIndex[$id]}.qty" => 'Quantité invalide (de 1 à 99).']);
            }
            $items[] = ['productId' => $id, 'name' => $product->getName(), 'price' => Money::fromCents($product->getPriceCents()), 'qty' => $qty];
            $total += $product->getPriceCents() * $qty;
        }

        if ($this->stripe->isEnabled() && $total < StripeGateway::MIN_CENTS) {
            throw new ValidationFailedException(['items' => 'Le montant minimum d’une commande payée en ligne est de 0,50 €.']);
        }

        $order = (new Order())->setCustomer($input->name, $input->email)->setItems($items)->setTotalCents($total)->setUser($user);

        // Réservation du stock + enregistrement de la commande dans UNE transaction : tout ou rien
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $this->stock->reserve($qtyById, $locale);
            $this->em->persist($order);
            $this->em->flush();
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        if (!$this->stripe->isEnabled()) {
            $this->mailer->sendOrderConfirmation($order);

            return new PlacedOrder($order);
        }

        try {
            $session = $this->stripe->createCheckoutSession($order, $locale);
        } catch (\Throwable $e) {
            // Stripe injoignable ou refus : on n'abandonne pas une commande fantôme « en attente »
            $this->logger->error('Stripe : page de paiement impossible à créer ('.$e->getMessage().')', ['order' => $order->getReference()]);
            $order->setStatus(OrderStatus::Cancelled);
            $this->em->flush();
            $this->stock->release($order); // les pièces redeviennent disponibles
            throw new ServiceUnavailableHttpException(30, $locale === 'en'
                ? 'Online payment is temporarily unavailable. Please try again in a few moments.'
                : 'Le paiement en ligne est momentanément indisponible. Réessayez dans quelques instants.');
        }
        $order->setStripeSessionId($session['id']);
        $this->em->flush();

        // L'e-mail de confirmation part quand le paiement est confirmé (PaymentService), pas maintenant.
        return new PlacedOrder($order, $session['url']);
    }
}
