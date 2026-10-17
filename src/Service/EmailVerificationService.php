<?php

namespace App\Service;

use App\Dto\VerificationResult;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Confirmation d'e-mail : jeton aléatoire envoyé par e-mail, hash SHA-256 en base, durée de vie limitée.
final class EmailVerificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly AppMailer $mailer,
        #[Autowire('%env(int:VERIFICATION_TTL_HOURS)%')] private readonly int $ttlHours,
    ) {
    }

    /** Génère un nouveau jeton (l'ancien devient invalide) et envoie l'e-mail. Renvoie false si l'envoi a échoué. */
    public function issueAndSend(User $user): bool
    {
        $token = bin2hex(random_bytes(32));
        $user->setVerificationHash(hash('sha256', $token))
            ->setVerificationExpiresAt(new \DateTimeImmutable("+{$this->ttlHours} hours"));
        $this->em->flush();

        return $this->mailer->sendVerification($user, $token);
    }

    // Idempotent : un lien déjà utilisé (ou ouvert par un antivirus) renvoie "ok" pour un compte déjà confirmé.
    public function verify(string $token): VerificationResult
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return VerificationResult::Invalid;
        }
        $user = $this->users->findOneByVerificationHash(hash('sha256', $token));
        if ($user === null) {
            return VerificationResult::Invalid;
        }
        if ($user->isVerified()) {
            return VerificationResult::Ok;
        }
        $expires = $user->getVerificationExpiresAt();
        if ($expires === null || $expires < new \DateTimeImmutable()) {
            return VerificationResult::Expired;
        }
        $user->setVerified(true);
        $this->em->flush();

        return VerificationResult::Ok;
    }
}
