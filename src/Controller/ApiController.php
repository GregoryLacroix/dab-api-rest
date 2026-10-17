<?php

namespace App\Controller;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

abstract class ApiController extends AbstractController
{
    /** Corps JSON de la requête (objet attendu) */
    protected function body(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            throw new BadRequestHttpException('Le corps de la requête doit être un objet JSON valide.');
        }
    }

    /** Liste paginée : { data: [...], meta: { page, perPage, total, pageCount } } */
    protected function paginate(QueryBuilder $qb, Request $request, callable $map, int $defaultPerPage = 12): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = (int) $request->query->get('perPage', $defaultPerPage);
        $perPage = min(100, max(1, $perPage));

        $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);
        $paginator = new Paginator($qb, false);
        $total = count($paginator);

        return new JsonResponse([
            'data' => array_map($map, iterator_to_array($paginator, false)),
            'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'pageCount' => max(1, (int) ceil($total / $perPage))],
        ]);
    }

    protected function throttle(RateLimiterFactory $factory, string $key): void
    {
        $limit = $factory->create($key)->consume();
        if (!$limit->isAccepted()) {
            $wait = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($wait, 'Trop de demandes. Réessayez dans quelques minutes.');
        }
    }
}
