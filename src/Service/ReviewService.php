<?php

namespace App\Service;

use App\Entity\Order;
use App\Entity\OrderStatus;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\OrderRepository;

// Règle « acheteur vérifié » : seule une personne ayant payé une commande contenant ce produit peut donner son avis
final class ReviewService
{
    public function __construct(private readonly OrderRepository $orders)
    {
    }

    public function hasPurchased(User $user, Product $product): bool
    {
        foreach ($this->orders->findForPerson($user) as $order) {
            /** @var Order $order */
            if (!\in_array($order->getStatus(), [OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Delivered], true)) {
                continue;
            }
            foreach ($order->getItems() as $item) {
                if ((int) ($item['productId'] ?? 0) === $product->getId()) {
                    return true;
                }
            }
        }

        return false;
    }
}
