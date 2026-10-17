<?php

namespace App\Controller;

use App\Entity\Money;
use App\Entity\Order;
use App\Repository\CategoryRepository;
use App\Repository\ContactMessageRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Chiffres du "Tableau de bord" du back-office
final class AdminStatsController extends ApiController
{
    #[Route('/api/admin/stats', name: 'api_admin_stats', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function stats(ProductRepository $products, CategoryRepository $categories, OrderRepository $orders, ContactMessageRepository $messages): JsonResponse
    {
        $recent = $orders->search(null, null)->setMaxResults(5)->getQuery()->getResult();

        return new JsonResponse([
            'products' => ['total' => $products->count([]), 'published' => $products->count(['published' => true])],
            'categories' => $categories->count([]),
            'orders' => [
                'total' => $orders->count([]),
                'toHandle' => $orders->countToHandle(),
            ],
            'messages' => ['total' => $messages->count([]), 'unread' => $messages->count(['isRead' => false])],
            'revenue' => Money::fromCents($orders->revenueCents()),
            'recentOrders' => array_map(fn (Order $o) => $o->toArray(), $recent),
        ]);
    }
}
