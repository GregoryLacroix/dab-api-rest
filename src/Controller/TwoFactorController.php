<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\ApiTokenService;
use App\Service\TwoFactorService;
use App\Service\ValidationFailedException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Double authentification (application d'authentification). Activation / désactivation depuis « Mon compte » ; /verify = 2e étape de connexion.
#[Route('/api/auth/2fa')]
final class TwoFactorController extends ApiController
{
    public function __construct(
        private readonly TwoFactorService $service,
        private readonly ApiTokenService $tokens,
        private readonly UserPasswordHasherInterface $hasher,
        #[Autowire(service: 'limiter.two_factor')] private readonly RateLimiterFactory $limiter,
    ) {
    }

    // État : { enabled, enabledAt, recoveryCodesLeft }
    #[Route('', name: 'api_2fa_status', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function status(): JsonResponse
    {
        $user = $this->me();

        return new JsonResponse([
            'enabled' => $this->service->isEnabled($user),
            'enabledAt' => $user->getTotpEnabledAt()?->format(\DATE_ATOM),
            'recoveryCodesLeft' => $this->service->recoveryCodesLeft($user),
        ]);
    }

    // Étape 1 de l'activation : { "password": "…" } (si le compte en a un) -> { secret, otpauthUrl } à scanner dans l'application
    #[Route('/setup', name: 'api_2fa_setup', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function setup(Request $request): JsonResponse
    {
        $user = $this->me();
        $this->throttle($this->limiter, 'u'.$user->getId());
        $this->confirmPassword($user, $this->body($request));

        return new JsonResponse($this->service->beginSetup($user));
    }

    // Étape 2 : { "code": "123456" } -> active la double authentification et renvoie les codes de secours (affichés une seule fois)
    #[Route('/enable', name: 'api_2fa_enable', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function enable(Request $request): JsonResponse
    {
        $user = $this->me();
        $this->throttle($this->limiter, 'u'.$user->getId());
        $code = $this->body($request)['code'] ?? '';

        return new JsonResponse(['recoveryCodes' => $this->service->confirmSetup($user, \is_string($code) ? $code : '')]);
    }

    // { "password": "…", "code": "123456 ou code de secours" } : mot de passe ET code exigés
    #[Route('/disable', name: 'api_2fa_disable', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function disable(Request $request): JsonResponse
    {
        $user = $this->authorizeSensitive($request);
        $this->service->disable($user);

        return new JsonResponse(['enabled' => false]);
    }

    // { "password": "…", "code": "…" } -> de nouveaux codes de secours (les anciens ne marchent plus)
    #[Route('/recovery-codes', name: 'api_2fa_recovery', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $this->authorizeSensitive($request);

        return new JsonResponse(['recoveryCodes' => $this->service->regenerateRecoveryCodes($user)]);
    }

    // 2e étape de la connexion (publique) : { "challenge": "…", "code": "123456 ou code de secours" } -> même réponse qu'une connexion classique
    #[Route('/verify', name: 'api_2fa_verify', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $user = $this->service->userFromChallenge(\is_string($body['challenge'] ?? null) ? $body['challenge'] : '')
            ?? throw new BadRequestHttpException('La connexion a expiré : recommencez depuis la page de connexion.');
        // Limites par compte ET par adresse IP : impossible de deviner un code à 6 chiffres par essais répétés
        $this->throttle($this->limiter, 'u'.$user->getId());
        $this->throttle($this->limiter, ($request->getClientIp() ?? 'unknown').'|2fa');

        if (!$this->service->verifyLogin($user, \is_string($body['code'] ?? null) ? $body['code'] : '')) {
            throw new ValidationFailedException(['code' => 'Code incorrect ou expiré.']);
        }

        return new JsonResponse($this->tokens->issue($user) + ['user' => $user->toArray()]);
    }

    /** Désactivation / nouveaux codes : mot de passe (si le compte en a un) ET code de la double authentification. */
    private function authorizeSensitive(Request $request): User
    {
        $user = $this->me();
        if (!$this->service->isEnabled($user)) {
            throw new ConflictHttpException('La double authentification n’est pas activée.');
        }
        $this->throttle($this->limiter, 'u'.$user->getId());
        $body = $this->body($request);
        $this->confirmPassword($user, $body);
        if (!$this->service->verifyLogin($user, \is_string($body['code'] ?? null) ? $body['code'] : '')) {
            throw new ValidationFailedException(['code' => 'Code incorrect ou expiré.']);
        }

        return $user;
    }

    private function confirmPassword(User $user, array $body): void
    {
        if (!$user->hasPassword()) {
            return; // compte créé avec Google : pas de mot de passe à confirmer
        }
        $password = \is_string($body['password'] ?? null) ? $body['password'] : '';
        if (!$this->hasher->isPasswordValid($user, $password)) {
            throw new ValidationFailedException(['password' => 'Mot de passe incorrect.']);
        }
    }

    private function me(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
