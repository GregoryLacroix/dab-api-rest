<?php

namespace App\Controller;

use App\Entity\Faq;
use App\Repository\FaqRepository;
use App\Service\ValidationFailedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Foire aux questions : lecture publique, édition dans le back-office
#[Route('/api')]
final class FaqController extends ApiController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly FaqRepository $repo)
    {
    }

    #[Route('/faq', name: 'api_faq_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $res = $this->paginate($this->repo->listQuery(true), $request, fn (Faq $f) => $f->toArray(), 100);
        $res->headers->set('Cache-Control', 'public, max-age=60');

        return $res;
    }

    #[Route('/admin/faq', name: 'api_faq_admin', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function admin(Request $request): JsonResponse
    {
        return $this->paginate($this->repo->listQuery(false), $request, fn (Faq $f) => $f->toArray(), 100);
    }

    #[Route('/admin/faq', name: 'api_faq_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $f = new Faq();
        $this->fill($f, $this->body($request), true);
        $this->em->persist($f);
        $this->em->flush();

        return new JsonResponse($f->toArray(), 201);
    }

    #[Route('/admin/faq/{id}', name: 'api_faq_update', requirements: ['id' => '\d+'], methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(int $id, Request $request): JsonResponse
    {
        $f = $this->repo->find($id) ?? throw new NotFoundHttpException();
        $this->fill($f, $this->body($request), false);
        $this->em->flush();

        return new JsonResponse($f->toArray());
    }

    #[Route('/admin/faq/{id}', name: 'api_faq_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(int $id): Response
    {
        $f = $this->repo->find($id) ?? throw new NotFoundHttpException();
        $this->em->remove($f);
        $this->em->flush();

        return new Response('', 204);
    }

    /** @param array<string, mixed> $b */
    private function fill(Faq $f, array $b, bool $creating): void
    {
        $err = [];
        $str = static fn (string $k): ?string => \is_string($b[$k] ?? null) && trim($b[$k]) !== '' ? trim($b[$k]) : null;
        foreach (['question' => [200, 'setQuestion'], 'answer' => [3000, 'setAnswer']] as $k => [$max, $set]) {
            if ($creating || \array_key_exists($k, $b)) {
                $v = $str($k);
                if ($v === null || mb_strlen($v) > $max) {
                    $err[$k] = "Texte requis ($max caractères maximum).";
                } else {
                    $f->$set($v);
                }
            }
        }
        foreach (['questionEn' => [200, 'setQuestionEn'], 'answerEn' => [3000, 'setAnswerEn']] as $k => [$max, $set]) {
            if (\array_key_exists($k, $b)) {
                $v = $str($k);
                if ($v !== null && mb_strlen($v) > $max) {
                    $err[$k] = "Texte trop long ($max caractères maximum).";
                } else {
                    $f->$set($v);
                }
            }
        }
        if (\array_key_exists('position', $b)) {
            if (!is_numeric($b['position']) || $b['position'] < 0 || $b['position'] > 100000) {
                $err['position'] = 'Position invalide.';
            } else {
                $f->setPosition((int) $b['position']);
            }
        }
        if (\array_key_exists('published', $b)) {
            $f->setPublished((bool) $b['published']);
        }
        if ($err !== []) {
            throw new ValidationFailedException($err);
        }
    }
}
