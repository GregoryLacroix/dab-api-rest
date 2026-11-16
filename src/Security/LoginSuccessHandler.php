<?php

namespace App\Security;

use App\Entity\User;
use App\Service\ApiTokenService;
use App\Service\TwoFactorService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

final class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(private readonly ApiTokenService $tokens, private readonly TwoFactorService $twoFactor)
    {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): JsonResponse
    {
        /** @var User $user */
        $user = $token->getUser();

        // Double authentification activée : le mot de passe ne suffit pas, aucun jeton n'est remis. Le site demande le code, puis
        // appelle POST /api/auth/2fa/verify avec ce « challenge » (valable 5 minutes).
        if ($this->twoFactor->isEnabled($user)) {
            return new JsonResponse(['twoFactor' => true, 'challenge' => $this->twoFactor->issueChallenge($user)]);
        }

        return new JsonResponse($this->tokens->issue($user) + ['user' => $user->toArray()]);
    }
}
