<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

// Échecs de connexion (login) et jeton invalide (routes protégées) : toujours du JSON
final class AuthFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): JsonResponse
    {
        if ($exception instanceof UnverifiedEmailException) {
            return new JsonResponse(['error' => 'email_not_verified', 'message' => $exception->getMessage()], 403);
        }
        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            return new JsonResponse(['error' => 'too_many_attempts', 'message' => 'Trop de tentatives de connexion. Réessayez dans quelques minutes.'], 429);
        }
        if (str_ends_with($request->getPathInfo(), '/auth/login')) {
            return new JsonResponse(['error' => 'invalid_credentials', 'message' => 'E-mail ou mot de passe incorrect.'], 401);
        }

        return new JsonResponse(['error' => 'invalid_token', 'message' => 'Session expirée ou invalide : reconnectez-vous.'], 401);
    }
}
