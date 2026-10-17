<?php

namespace App\Security;

use App\Repository\ApiTokenRepository;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

// Authorization: Bearer <jeton> -> utilisateur
final class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(private readonly ApiTokenRepository $tokens)
    {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $token = $this->tokens->findValid(hash('sha256', $accessToken));
        if ($token === null) {
            throw new BadCredentialsException('Jeton invalide ou expiré.');
        }
        $user = $token->getUser();

        return new UserBadge($user->getUserIdentifier(), static fn () => $user);
    }
}
