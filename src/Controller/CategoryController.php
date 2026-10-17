<?php

namespace App\Controller;

use App\Dto\CategoryInput;
use App\Entity\Category;
use App\Repository\CategoryRepository;
use App\Service\ApiValidator;
use App\Service\ValidationFailedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/categories')]
final class CategoryController extends ApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CategoryRepository $categories,
        private readonly ApiValidator $validator,
    ) {
    }

    // Public (filtres de la boutique). Par défaut toutes les catégories (perPage=100).
    #[Route('', name: 'api_categories_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        return $this->paginate($this->categories->ordered(), $request, fn (Category $c) => $c->toArray(), 100);
    }

    #[Route('', name: 'api_categories_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $input = $this->validated($request);
        if ($this->categories->nameExists($input->name)) {
            throw new ValidationFailedException(['name' => 'Cette catégorie existe déjà.']);
        }
        $category = (new Category())
            ->setId($this->uniqueId($input->name))
            ->setName($input->name)
            ->setPosition($this->categories->nextPosition());
        $this->em->persist($category);
        $this->em->flush();

        return new JsonResponse($category->toArray(), 201);
    }

    #[Route('/{id}', name: 'api_categories_update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(string $id, Request $request): JsonResponse
    {
        $category = $this->find($id);
        $input = $this->validated($request);
        if ($this->categories->nameExists($input->name, $category->getId())) {
            throw new ValidationFailedException(['name' => 'Cette catégorie existe déjà.']);
        }
        $category->setName($input->name);
        $this->em->flush();

        return new JsonResponse($category->toArray());
    }

    // Comme le React : impossible de supprimer une catégorie qui contient encore des produits
    #[Route('/{id}', name: 'api_categories_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(string $id): Response
    {
        $category = $this->find($id);
        if ($category->getProducts()->count() > 0) {
            throw new ConflictHttpException('Cette catégorie contient encore des produits : déplacez-les ou supprimez-les d’abord.');
        }
        $this->em->remove($category);
        $this->em->flush();

        return new Response(status: 204);
    }

    private function validated(Request $request): CategoryInput
    {
        $input = CategoryInput::fromArray($this->body($request));
        $this->validator->check($input);

        return $input;
    }

    private function find(string $id): Category
    {
        return $this->categories->find($id) ?? throw new NotFoundHttpException();
    }

    private function uniqueId(string $name): string
    {
        $base = strtolower((string) (new \Symfony\Component\String\Slugger\AsciiSlugger('fr'))->slug($name)) ?: 'categorie';
        $id = $base;
        for ($n = 2; $this->categories->find($id) !== null; ++$n) {
            $id = "$base-$n";
        }

        return $id;
    }
}
