<?php

namespace App\Controller;

use App\Entity\Realisation;
use App\Repository\RealisationRepository;
use App\Service\ValidationFailedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Galerie de réalisations (fresques et décors muraux déjà peints)
#[Route('/api')]
final class RealisationController extends ApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RealisationRepository $repo,
    ) {
    }

    // Public : réalisations publiées. ?page=1 &perPage=100
    #[Route('/realisations', name: 'api_realisations_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $res = $this->paginate($this->repo->listQuery(true), $request, fn (Realisation $r) => $r->toArray(), 100);
        $res->headers->set('Cache-Control', 'public, max-age=60');

        return $res;
    }

    #[Route('/realisations/{id}', name: 'api_realisations_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $r = $this->repo->find($id);
        if ($r === null || !$r->isPublished()) {
            throw new NotFoundHttpException();
        }

        return new JsonResponse($r->toArray(), 200, ['Cache-Control' => 'public, max-age=60']);
    }

    #[Route('/admin/realisations', name: 'api_realisations_admin', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function admin(Request $request): JsonResponse
    {
        return $this->paginate($this->repo->listQuery(false), $request, fn (Realisation $r) => $r->toArray(), 50);
    }

    #[Route('/admin/realisations', name: 'api_realisations_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $r = new Realisation();
        $this->fill($r, $this->body($request), true);
        $this->em->persist($r);
        $this->em->flush();

        return new JsonResponse($r->toArray(), 201);
    }

    #[Route('/admin/realisations/{id}', name: 'api_realisations_update', requirements: ['id' => '\d+'], methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(int $id, Request $request): JsonResponse
    {
        $r = $this->repo->find($id) ?? throw new NotFoundHttpException();
        $this->fill($r, $this->body($request), false);
        $this->em->flush();

        return new JsonResponse($r->toArray());
    }

    #[Route('/admin/realisations/{id}', name: 'api_realisations_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(int $id): Response
    {
        $r = $this->repo->find($id) ?? throw new NotFoundHttpException();
        $this->em->remove($r);
        $this->em->flush();

        return new Response('', 204);
    }

    /** @param array<string, mixed> $b */
    private function fill(Realisation $r, array $b, bool $creating): void
    {
        $err = [];
        $text = static fn (string $k, int $max): ?string => \is_string($b[$k] ?? null) && trim($b[$k]) !== '' ? trim($b[$k]) : null;

        if ($creating || \array_key_exists('title', $b)) {
            $title = $text('title', 160);
            if ($title === null || mb_strlen($title) > 160) {
                $err['title'] = 'Saisissez un titre (160 caractères maximum).';
            } else {
                $r->setTitle($title);
            }
        }
        foreach (['titleEn' => [160, 'setTitleEn'], 'location' => [160, 'setLocation'], 'tag' => [60, 'setTag'], 'tagEn' => [60, 'setTagEn'], 'description' => [5000, 'setDescription'], 'descriptionEn' => [5000, 'setDescriptionEn']] as $key => [$max, $setter]) {
            if (!\array_key_exists($key, $b)) {
                continue;
            }
            $v = $text($key, $max);
            if ($v !== null && mb_strlen($v) > $max) {
                $err[$key] = "Texte trop long ($max caractères maximum).";
            } else {
                $r->$setter($v);
            }
        }
        if (\array_key_exists('images', $b)) {
            $imgs = \is_array($b['images']) ? array_values($b['images']) : [];
            if (\count($imgs) > 12 || array_filter($imgs, static fn ($u) => !\is_string($u) || !preg_match('~^https?://\S{1,480}$~', $u)) !== []) {
                $err['images'] = 'Photos invalides (12 maximum, adresses http(s)).';
            } else {
                $r->setImages($imgs);
            }
        }
        if (\array_key_exists('beforeImage', $b)) {
            $u = $b['beforeImage'];
            if ($u === null || $u === '') {
                $r->setBeforeImage(null);
            } elseif (!\is_string($u) || !preg_match('~^https?://\S{1,480}$~', $u)) {
                $err['beforeImage'] = 'Photo invalide.';
            } else {
                $r->setBeforeImage($u);
            }
        }
        if (\array_key_exists('completedAt', $b)) {
            $v = $b['completedAt'];
            if ($v === null || $v === '') {
                $r->setCompletedAt(null);
            } else {
                try {
                    $r->setCompletedAt(new \DateTimeImmutable((string) $v, new \DateTimeZone('UTC')));
                } catch (\Exception) {
                    $err['completedAt'] = 'Date invalide.';
                }
            }
        }
        if (\array_key_exists('position', $b)) {
            if (!is_numeric($b['position']) || $b['position'] < 0 || $b['position'] > 100000) {
                $err['position'] = 'Position invalide.';
            } else {
                $r->setPosition((int) $b['position']);
            }
        }
        if (\array_key_exists('published', $b)) {
            $r->setPublished((bool) $b['published']);
        }
        if ($err !== []) {
            throw new ValidationFailedException($err);
        }
    }
}
