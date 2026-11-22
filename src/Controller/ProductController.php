<?php

namespace App\Controller;

use App\Dto\ProductInput;
use App\Entity\Category;
use App\Entity\Money;
use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Repository\StockAlertRepository;
use App\Service\ApiValidator;
use App\Service\StockAlertService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use App\Service\ValidationFailedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/products')]
final class ProductController extends ApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductRepository $products,
        private readonly ApiValidator $validator,
        private readonly StockAlertService $stockAlerts,
        private readonly StockAlertRepository $alertRepo,
        #[Autowire(service: 'limiter.stock_alert')] private readonly RateLimiterFactory $alertLimiter,
    ) {
    }

    /**
     * Visiteurs : uniquement les produits publiés. Administrateur : tous (filtre ?published=1|0).
     * Filtres : ?category=galets &q=vague &sort=new|price_asc|price_desc &available=1 &page=1 &perPage=4 (scroll infini : page suivante tant que page < meta.pageCount)
     */
    #[Route('', name: 'api_products_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $published = true;
        if ($this->isGranted('ROLE_ADMIN')) {
            $raw = $request->query->get('published');
            $published = ($raw === null || $raw === '') ? null : filter_var($raw, FILTER_VALIDATE_BOOLEAN);
        }
        $sort = $request->query->get('sort');
        $qb = $this->products->search($request->query->get('category'), $request->query->get('q'), $published, [
            'sort' => in_array($sort, ['new', 'price_asc', 'price_desc'], true) ? $sort : null,
            'available' => filter_var($request->query->get('available'), FILTER_VALIDATE_BOOLEAN),
        ]);

        return $this->paginate($qb, $request, fn (Product $p) => $p->toArray(), 12);
    }

    #[Route('/{id}', name: 'api_products_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $product = $this->products->find($id);
        // Un brouillon est introuvable pour les visiteurs (comme la fiche produit du React)
        if ($product === null || (!$product->isPublished() && !$this->isGranted('ROLE_ADMIN'))) {
            throw new NotFoundHttpException();
        }

        return new JsonResponse($product->toArray());
    }

    #[Route('', name: 'api_products_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $product = new Product();
        $this->save($product, $this->body($request));

        return new JsonResponse($product->toArray(), 201);
    }

    // PUT : remplace le produit (champs obligatoires requis). PATCH : ne modifie que les champs envoyés.
    #[Route('/{id}', name: 'api_products_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(int $id, Request $request): JsonResponse
    {
        $product = $this->products->find($id) ?? throw new NotFoundHttpException();
        $data = $this->body($request);
        if ($request->getMethod() === 'PATCH') {
            $data = array_replace($product->toArray(), $data);
        }
        $wasSoldOut = $product->getStock() <= 0;
        $this->save($product, $data);
        if ($wasSoldOut) {
            $this->stockAlerts->notifyRestocked($product); // de nouveau en stock : prévient les inscrits « prévenez-moi »
        }

        return new JsonResponse($product->toArray());
    }

    // « Prévenez-moi » : { email } (produit épuisé uniquement). 204 même si l'adresse était déjà inscrite.
    #[Route('/{id}/stock-alert', name: 'api_products_stock_alert', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function stockAlert(int $id, Request $request): Response
    {
        $this->throttle($this->alertLimiter, $request->getClientIp() ?? 'unknown');
        $product = $this->products->find($id);
        if ($product === null || !$product->isPublished()) {
            throw new NotFoundHttpException();
        }
        $email = \is_string($this->body($request)['email'] ?? null) ? mb_strtolower(trim($this->body($request)['email'])) : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || \strlen($email) > 180) {
            throw new ValidationFailedException(['email' => 'Saisissez une adresse e-mail valide.']);
        }
        $this->stockAlerts->subscribe($product, $email, $request->getPreferredLanguage(['fr', 'en']) ?? 'fr');

        return new Response(status: 204);
    }

    // Back-office : produits les plus demandés en alerte « prévenez-moi » -> [{ productId, name, stock, count }]
    #[Route('/stock-alerts', name: 'api_products_stock_alerts', methods: ['GET'], priority: 10)]
    #[IsGranted('ROLE_ADMIN')]
    public function stockAlertCounts(): JsonResponse
    {
        $out = [];
        foreach ($this->alertRepo->countsByProduct() as $row) {
            $p = $this->products->find($row['productId']);
            if ($p !== null) {
                $out[] = ['productId' => $p->getId(), 'name' => $p->getName(), 'stock' => $p->getStock(), 'count' => $row['count']];
            }
        }

        return new JsonResponse($out);
    }

    #[Route('/{id}', name: 'api_products_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(int $id): Response
    {
        $product = $this->products->find($id) ?? throw new NotFoundHttpException();
        $this->em->remove($product);
        $this->em->flush();

        return new Response(status: 204);
    }

    private function save(Product $product, array $data): void
    {
        $input = ProductInput::fromArray($data);
        $this->validator->check($input);
        $category = $this->em->find(Category::class, $input->categoryId)
            ?? throw new ValidationFailedException(['categoryId' => 'Catégorie introuvable.']);

        $product->setName($input->name)
            ->setCategory($category)
            ->setPriceCents(Money::toCents($input->price))
            ->setDescription($input->description)
            ->setPublished($input->published)
            ->setEnglish($input->nameEn, $input->descriptionEn)
            ->setSizeLabel($input->sizeLabel)
            ->setWidthCm($input->widthCm)
            ->setHeightCm($input->heightCm)
            ->setDepthCm($input->depthCm)
            ->setWeightG($input->weightG)
            ->setImages($input->cleanImages());
        if ($input->stock !== null) {
            $product->setStock($input->stock);
        }
        $this->em->persist($product);
        $this->em->flush();
    }
}
