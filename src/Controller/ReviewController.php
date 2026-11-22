<?php

namespace App\Controller;

use App\Dto\ReviewInput;
use App\Entity\Product;
use App\Entity\Review;
use App\Entity\ReviewStatus;
use App\Entity\User;
use App\Repository\ProductRepository;
use App\Repository\ReviewRepository;
use App\Service\ApiValidator;
use App\Service\AppMailer;
use App\Service\ReviewService;
use App\Service\ValidationFailedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
final class ReviewController extends ApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReviewRepository $reviews,
        private readonly ProductRepository $products,
        private readonly ReviewService $service,
        private readonly ApiValidator $validator,
        private readonly AppMailer $mailer,
        #[Autowire(service: 'limiter.review')] private readonly RateLimiterFactory $reviewLimiter,
        #[Autowire('%env(CONTACT_EMAIL)%')] private readonly string $shopEmail,
    ) {
    }

    // Public : avis publiés d'un produit + note moyenne. ?page=1 &perPage=10
    #[Route('/products/{id}/reviews', name: 'api_reviews_index', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(int $id, Request $request): JsonResponse
    {
        $product = $this->product($id);
        $list = $this->paginate($this->reviews->approvedQuery($product), $request, fn (Review $r) => $r->toPublicArray(), 10);
        $payload = json_decode((string) $list->getContent(), true);
        $payload['summary'] = $this->reviews->summary($product);

        return new JsonResponse($payload, 200, ['Cache-Control' => 'public, max-age=60']);
    }

    // Public : meilleurs avis récents pour l'accueil + note moyenne de la boutique : { data: [{ …avis, productId, productName }], summary: { average, count } }
    #[Route('/reviews/featured', name: 'api_reviews_featured', methods: ['GET'])]
    public function featured(): JsonResponse
    {
        $data = array_map(
            static fn (Review $r) => $r->toPublicArray() + [
                'productId' => $r->getProduct()->getId(), 'productName' => $r->getProduct()->getName(),
                'productNameEn' => $r->getProduct()->translations()['en']['name'] ?? null,
            ],
            $this->reviews->featured(6),
        );

        return new JsonResponse(['data' => $data, 'summary' => $this->reviews->globalSummary()], 200, ['Cache-Control' => 'public, max-age=300']);
    }

    // Connecté : puis-je donner mon avis sur ce produit ? { eligible, reason: purchase|already|null, review }
    #[Route('/products/{id}/reviews/mine', name: 'api_reviews_mine', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function mine(int $id): JsonResponse
    {
        $user = $this->currentUser();
        $product = $this->product($id);
        $review = $this->reviews->findOneByUserAndProduct($user, $product);
        $reason = $review !== null ? 'already' : ($this->service->hasPurchased($user, $product) ? null : 'purchase');

        return new JsonResponse(
            ['eligible' => $reason === null, 'reason' => $reason, 'review' => $review?->toArray()],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }

    // Connecté + acheteur : { rating, title?, body } -> enregistré « en attente de modération »
    #[Route('/products/{id}/reviews', name: 'api_reviews_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function create(int $id, Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $this->throttle($this->reviewLimiter, 'u'.$user->getId());
        $product = $this->product($id);

        $input = ReviewInput::fromArray($this->body($request));
        $this->validator->check($input);
        if ($this->reviews->findOneByUserAndProduct($user, $product) !== null) {
            throw new ConflictHttpException('Vous avez déjà donné votre avis sur ce produit.');
        }
        if (!$this->service->hasPurchased($user, $product)) {
            throw new AccessDeniedHttpException('Seuls les clients ayant acheté ce produit peuvent donner leur avis.');
        }

        $review = (new Review())->setProduct($product)->setUser($user)
            ->setContent(Review::displayName($user->getName()), $input->rating, $input->title, $input->body);
        $this->em->persist($review);
        $this->em->flush();
        $this->mailer->sendReviewNotification($review, $this->shopEmail);

        return new JsonResponse($review->toArray(), 201);
    }

    // Supprimer son propre avis (pour en écrire un nouveau, par exemple)
    #[Route('/reviews/{id}', name: 'api_reviews_delete_own', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function deleteOwn(int $id): Response
    {
        $review = $this->reviews->find($id) ?? throw new NotFoundHttpException();
        if ($review->getUser()?->getId() !== $this->currentUser()->getId()) {
            throw new NotFoundHttpException();
        }
        $this->em->remove($review);
        $this->em->flush();

        return new Response(status: 204);
    }

    // ---- Back-office -------------------------------------------------------------------------------------------

    // ?status=pending|approved|rejected &q=… &page=1 &perPage=10
    #[Route('/admin/reviews', name: 'api_admin_reviews_index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminIndex(Request $request): JsonResponse
    {
        return $this->paginate($this->reviews->search($request->query->get('status'), $request->query->get('q')), $request, fn (Review $r) => $r->toArray(), 10);
    }

    // { "status": "approved"|"rejected"|"pending", "reply": "Merci !" } (les deux champs sont facultatifs)
    #[Route('/admin/reviews/{id}', name: 'api_admin_reviews_update', requirements: ['id' => '\d+'], methods: ['PATCH', 'PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminUpdate(int $id, Request $request): JsonResponse
    {
        $review = $this->reviews->find($id) ?? throw new NotFoundHttpException();
        $body = $this->body($request);
        if (array_key_exists('status', $body)) {
            $status = is_string($body['status']) ? ReviewStatus::tryFrom($body['status']) : null;
            $status ?? throw new ValidationFailedException(['status' => 'Statut invalide (pending, approved ou rejected).']);
            $review->setStatus($status);
        }
        if (array_key_exists('reply', $body)) {
            if ($body['reply'] !== null && (!is_string($body['reply']) || mb_strlen($body['reply']) > 1000)) {
                throw new ValidationFailedException(['reply' => 'Réponse invalide (1000 caractères maximum).']);
            }
            $review->setReply($body['reply']);
        }
        $this->em->flush();

        return new JsonResponse($review->toArray());
    }

    #[Route('/admin/reviews/{id}', name: 'api_admin_reviews_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminDelete(int $id): Response
    {
        $this->em->remove($this->reviews->find($id) ?? throw new NotFoundHttpException());
        $this->em->flush();

        return new Response(status: 204);
    }

    private function product(int $id): Product
    {
        $product = $this->products->find($id);
        // Un produit non publié n'existe pas pour le public
        if ($product === null || (!$product->isPublished() && !$this->isGranted('ROLE_ADMIN'))) {
            throw new NotFoundHttpException();
        }

        return $product;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
