<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

// Double authentification par application (TOTP) : activation, codes de connexion, codes de secours, étape intermédiaire de connexion.
//   1. mot de passe (ou Google) correct -> PAS de jeton de connexion, mais un « challenge » signé de 5 minutes ;
//   2. code à 6 chiffres (ou code de secours) + challenge -> jeton de connexion.
final class TwoFactorService
{
    private const ISSUER = 'Les galets d’Ambre';
    private const CHALLENGE_TTL = 300;
    private const RECOVERY_COUNT = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly Totp $totp,
        private readonly SecretBox $box,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {
    }

    public function isEnabled(User $user): bool
    {
        return $user->getTotpEnabledAt() !== null && $user->getTotpSecret() !== null;
    }

    public function recoveryCodesLeft(User $user): int
    {
        return \count($user->getRecoveryCodes());
    }

    /**
     * Démarre l'activation : nouveau secret, « en attente » tant que le premier code n'a pas été validé.
     *
     * @return array{secret: string, otpauthUrl: string}
     */
    public function beginSetup(User $user): array
    {
        if ($this->isEnabled($user)) {
            throw new ConflictHttpException('La double authentification est déjà activée.');
        }
        $secret = Totp::newSecret();
        $user->setTotpSecret($this->box->seal($secret))->setTotpLastStep(null);
        $this->em->flush();

        return ['secret' => $secret, 'otpauthUrl' => Totp::otpauthUrl(self::ISSUER, $user->getEmail(), $secret)];
    }

    /**
     * Valide le premier code : la double authentification devient active. Renvoie les codes de secours (affichés UNE seule fois).
     *
     * @return string[]
     */
    public function confirmSetup(User $user, string $code): array
    {
        if ($this->isEnabled($user)) {
            throw new ConflictHttpException('La double authentification est déjà activée.');
        }
        $secret = $this->plainSecret($user) ?? throw new ConflictHttpException('Commencez par lancer l’activation.');
        $step = $this->totp->verify($secret, $this->normalize($code));
        if ($step === null) {
            throw new ValidationFailedException(['code' => 'Code incorrect ou expiré. Vérifiez l’heure de votre téléphone et réessayez.']);
        }
        $plain = $this->newRecoveryCodes($user);
        $user->setTotpEnabledAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTotpLastStep($step);
        $this->em->flush();

        return $plain;
    }

    /** Code de connexion : 6 chiffres de l'application, ou code de secours (usage unique). */
    public function verifyLogin(User $user, string $input): bool
    {
        $code = $this->normalize($input);
        if (preg_match('/^\d{6}$/', $code)) {
            $secret = $this->plainSecret($user);
            $step = $secret === null ? null : $this->totp->verify($secret, $code, $user->getTotpLastStep());
            if ($step === null) {
                return false;
            }
            $user->setTotpLastStep($step); // ce code ne servira plus
            $this->em->flush();

            return true;
        }
        if (preg_match('/^[A-F0-9]{10}$/', $code)) {
            return $this->consumeRecoveryCode($user, $code);
        }

        return false;
    }

    public function disable(User $user): void
    {
        $user->setTotpSecret(null)->setTotpEnabledAt(null)->setTotpLastStep(null)->setRecoveryCodes([]);
        $this->em->flush();
    }

    /** @return string[] nouveaux codes de secours (les anciens deviennent inutilisables) */
    public function regenerateRecoveryCodes(User $user): array
    {
        $plain = $this->newRecoveryCodes($user);
        $this->em->flush();

        return $plain;
    }

    // ---- Étape intermédiaire de connexion ---------------------------------------------------------------------------------

    /** Jeton signé de 5 minutes prouvant que le mot de passe (ou Google) a déjà été validé. */
    public function issueChallenge(User $user): string
    {
        $payload = self::b64(json_encode(['u' => $user->getId(), 'e' => time() + self::CHALLENGE_TTL, 'n' => bin2hex(random_bytes(8))], \JSON_THROW_ON_ERROR));

        return $payload.'.'.$this->sign($payload);
    }

    public function userFromChallenge(string $challenge): ?User
    {
        $parts = explode('.', $challenge);
        if (\count($parts) !== 2 || !hash_equals($this->sign($parts[0]), $parts[1])) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (!\is_array($data) || !isset($data['u'], $data['e']) || $data['e'] < time()) {
            return null;
        }
        $user = $this->users->find((int) $data['u']);

        return $user !== null && $this->isEnabled($user) && $user->isVerified() ? $user : null;
    }

    // ---------------------------------------------------------------------------------------------------------------------

    private function plainSecret(User $user): ?string
    {
        return $user->getTotpSecret() === null ? null : $this->box->open($user->getTotpSecret());
    }

    /** Espaces, tirets et casse ignorés (les codes de secours s'écrivent XXXXX-XXXXX). */
    private function normalize(string $input): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/', '', $input));
    }

    /** @return string[] */
    private function newRecoveryCodes(User $user): array
    {
        $plain = [];
        $hashes = [];
        for ($i = 0; $i < self::RECOVERY_COUNT; ++$i) {
            $code = strtoupper(bin2hex(random_bytes(5))); // 10 caractères hexadécimaux
            $plain[] = substr($code, 0, 5).'-'.substr($code, 5);
            $hashes[] = $this->hashRecovery($code);
        }
        $user->setRecoveryCodes($hashes);

        return $plain;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $hash = $this->hashRecovery($code);
        $codes = $user->getRecoveryCodes();
        foreach ($codes as $i => $known) {
            if (hash_equals($known, $hash)) {
                unset($codes[$i]);
                $user->setRecoveryCodes($codes);
                $this->em->flush();

                return true;
            }
        }

        return false;
    }

    private function hashRecovery(string $code): string
    {
        return hash_hmac('sha256', $code, 'recovery|'.$this->secret);
    }

    private function sign(string $payload): string
    {
        return self::b64(hash_hmac('sha256', '2fa-challenge|'.$payload, $this->secret, true));
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
