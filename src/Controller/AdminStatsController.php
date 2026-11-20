<?php

namespace App\Controller;

use App\Entity\Money;
use App\Entity\Order;
use App\Repository\CategoryRepository;
use App\Service\AnalyticsService;
use Symfony\Component\HttpFoundation\Request;
use App\Repository\ContactMessageRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Repository\ReviewRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Chiffres du "Tableau de bord" du back-office
final class AdminStatsController extends ApiController
{
    #[Route('/api/admin/stats', name: 'api_admin_stats', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function stats(ProductRepository $products, CategoryRepository $categories, OrderRepository $orders, ContactMessageRepository $messages, ReviewRepository $reviews): JsonResponse
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
            'reviews' => ['pending' => $reviews->countPending()],
            'revenue' => Money::fromCents($orders->revenueCents()),
            'recentOrders' => array_map(fn (Order $o) => $o->toArray(), $recent),
        ]);
    }

    // Statistiques détaillées : ?period=7|30|90|365|all (30 par défaut)
    #[Route('/api/admin/analytics', name: 'api_admin_analytics', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function analytics(Request $request, AnalyticsService $analytics): JsonResponse
    {
        return new JsonResponse($analytics->compute((string) $request->query->get('period', '30')), 200, ['Cache-Control' => 'no-store']);
    }
}
