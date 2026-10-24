<?php

namespace App\Service;

use App\Dto\VerificationResult;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

// « Mot de passe oublié » : jeton aléatoire envoyé par e-mail (1 heure, usage unique), seul son hash SHA-256 est en base.
// Prouver qu'on lit la boîte e-mail suffit : cela fonctionne aussi pour un compte créé avec Google (qui obtient ainsi un mot de passe).
final class PasswordResetService
{
    private const TTL = '+1 hour';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ApiTokenRepository $tokens,
        private readonly AppMailer $mailer,
    ) {
    }

    /** Envoie le lien si le compte existe ; ne révèle jamais si l'adresse est connue (l'appelant répond pareil dans les deux cas). */
    public function request(string $email, string $lang): void
    {
        $user = $this->users->findOneByEmail($email);
        if ($user === null) {
            return;
        }
        $token = bin2hex(random_bytes(32));
        $user->setResetHash(hash('sha256', $token))->setResetExpiresAt(new \DateTimeImmutable(self::TTL));
        $this->em->flush();
        $this->mailer->sendPasswordReset($user, $token, $lang);
    }

    public function reset(string $token, string $newPassword): VerificationResult
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return VerificationResult::Invalid;
        }
        $user = $this->users->findOneByResetHash(hash('sha256', $token));
        if ($user === null) {
            return VerificationResult::Invalid;
        }
        $expires = $user->getResetExpiresAt();
        if ($expires === null || $expires < new \DateTimeImmutable()) {
            return VerificationResult::Expired;
        }

        $user->setPassword($this->hasher->hashPassword($user, $newPassword))->setResetHash(null)->setResetExpiresAt(null);
        if (!$user->isVerified()) {
            // La personne vient de prouver qu'elle lit cette boîte e-mail
            $user->setVerified(true)->setVerificationHash(null)->setVerificationExpiresAt(null);
        }
        $this->em->flush();
        $this->tokens->deleteForUser($user); // toutes les sessions ouvertes sont fermées

        return VerificationResult::Ok;
    }
}
