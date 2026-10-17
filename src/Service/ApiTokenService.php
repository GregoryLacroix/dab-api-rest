<?php

namespace App\Service;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Jetons d'accès (Authorization: Bearer …). Seul le hash SHA-256 est stocké en base.
final class ApiTokenService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ApiTokenRepository $tokens,
        #[Autowire('%env(int:API_TOKEN_TTL_DAYS)%')] private readonly int $ttlDays,
    ) {
    }

    /** @return array{token: string, expiresAt: string} */
    public function issue(User $user): array
    {
        $this->tokens->purgeExpired();
        $plain = bin2hex(random_bytes(32));
        $token = new ApiToken($user, hash('sha256', $plain), new \DateTimeImmutable("+{$this->ttlDays} days"));
        $this->em->persist($token);
        $this->em->flush();

        return ['token' => $plain, 'expiresAt' => $token->getExpiresAt()->format(\DATE_ATOM)];
    }

    public function revoke(string $plain): void
    {
        $token = $this->tokens->findOneBy(['tokenHash' => hash('sha256', $plain)]);
        if ($token !== null) {
            $this->em->remove($token);
            $this->em->flush();
        }
    }
}
