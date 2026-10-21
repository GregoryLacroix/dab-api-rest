<?php

namespace App\Controller;

use App\Entity\ContactMessage;
use App\Entity\Order;
use App\Entity\OrderStatus;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\ContactMessageRepository;
use App\Repository\OAuthIdentityRepository;
use App\Repository\OrderRepository;
use App\Service\ValidationFailedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Droits RGPD de la personne connectée : accès / portabilité (export) et effacement (suppression du compte)
#[Route('/api/auth/me')]
final class AccountController extends ApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrderRepository $orders,
        private readonly ContactMessageRepository $messages,
        private readonly ApiTokenRepository $tokens,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly OAuthIdentityRepository $identities,
    ) {
    }

    // « Mon compte » : historique des commandes de la personne (commandes liées au compte ou passées avec la même adresse e-mail).
    // Les paiements en ligne abandonnés (jamais payés, annulés) sont masqués : ce ne sont pas de vraies commandes.
    #[Route('/orders', name: 'api_account_orders', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function myOrders(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $real = array_filter(
            $this->orders->findForPerson($user),
            static fn (Order $o) => !($o->getStatus() === OrderStatus::Cancelled && $o->getPaidAt() === null && $o->getStripeSessionId() !== null),
        );

        return new JsonResponse(['items' => array_map(fn (Order $o) => $o->toArray(), array_values($real))], 200, ['Cache-Control' => 'no-store']);
    }

    // Copie de toutes les données liées à la personne (compte, commandes, messages)
    #[Route('/export', name: 'api_account_export', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function export(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return new JsonResponse([
            'exportedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'account' => $user->toArray() + ['createdAt' => $user->getCreatedAt()->format(\DATE_ATOM), 'signInProviders' => $this->identities->providersFor($user)],
            'orders' => array_map(fn (Order $o) => $o->toArray(), $this->orders->findForPerson($user)),
            'messages' => array_map(fn (ContactMessage $m) => $m->toArray(), $this->messages->findByEmail($user->getEmail())),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    // Suppression du compte, après confirmation par le mot de passe : { "password": "…" }
    // (compte créé via Google/Facebook, donc sans mot de passe : { "email": "…" }, l'adresse du compte)
    // Les commandes sont conservées 10 ans (obligation comptable) mais détachées du compte ; messages et jetons sont supprimés.
    #[Route('', name: 'api_account_delete', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function delete(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($user->isAdmin()) {
            throw new AccessDeniedHttpException('Un compte administrateur ne peut pas être supprimé depuis le site.');
        }
        $body = $this->body($request);
        if ($user->hasPassword()) {
            $password = $body['password'] ?? null;
            if (!is_string($password) || !$this->hasher->isPasswordValid($user, $password)) {
                throw new ValidationFailedException(['password' => 'Mot de passe incorrect.']);
            }
        } else {
            $email = $body['email'] ?? null;
            if (!is_string($email) || mb_strtolower(trim($email)) !== $user->getEmail()) {
                throw new ValidationFailedException(['password' => 'Saisissez l’adresse e-mail de votre compte pour confirmer.']);
            }
        }

        $this->messages->deleteByEmail($user->getEmail());
        $this->orders->detachUser($user);
        $this->tokens->deleteForUser($user);
        $this->identities->deleteForUser($user);
        $this->em->remove($user);
        $this->em->flush();

        return new Response(status: 204);
    }
}
