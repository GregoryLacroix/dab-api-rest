<?php

namespace App\Controller;

use App\Dto\RegisterInput;
use App\Dto\VerificationResult;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ApiTokenService;
use App\Service\ApiValidator;
use App\Service\EmailVerificationService;
use App\Service\PasswordResetService;
use App\Service\ValidationFailedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/auth')]
final class AuthController extends ApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ApiValidator $validator,
        private readonly EmailVerificationService $verification,
        private readonly ApiTokenService $tokens,
        private readonly PasswordResetService $passwordReset,
        #[Autowire(service: 'limiter.password_reset')] private readonly RateLimiterFactory $resetLimiter,
        #[Autowire(service: 'limiter.register')] private readonly RateLimiterFactory $registerLimiter,
        #[Autowire(service: 'limiter.resend')] private readonly RateLimiterFactory $resendLimiter,
        #[Autowire('%env(FRONTEND_URL)%')] private readonly string $frontendUrl,
    ) {
    }

    // Page "Créer un compte" -> compte NON confirmé + e-mail de vérification
    #[Route('/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $this->throttle($this->registerLimiter, $request->getClientIp() ?? 'unknown');

        $input = RegisterInput::fromArray($this->body($request));
        $this->validator->check($input);
        if ($this->users->findOneByEmail($input->email) !== null) {
            throw new ValidationFailedException(['email' => 'Cette adresse e-mail est déjà utilisée.']);
        }

        $user = (new User())->setEmail($input->email)->setName($input->name)->setRoles([]);
        $user->setPassword($this->hasher->hashPassword($user, $input->password));
        $this->em->persist($user);
        $this->em->flush();

        $sent = $this->verification->issueAndSend($user);

        return new JsonResponse([
            'message' => $sent
                ? 'Compte créé. Un e-mail de confirmation vient de vous être envoyé : cliquez sur le lien pour activer votre compte.'
                : 'Compte créé, mais l’e-mail de confirmation n’a pas pu être envoyé. Utilisez « Renvoyer l’e-mail ».',
            'emailSent' => $sent,
            'user' => $user->toArray(),
        ], 201);
    }

    // Lien reçu par e-mail : confirme puis redirige vers le React (/connexion?verified=1)
    #[Route('/verify-email', name: 'api_verify_email', methods: ['GET'])]
    public function verifyLink(Request $request): RedirectResponse
    {
        $result = $this->verification->verify((string) $request->query->get('token', ''));
        $query = $result === VerificationResult::Ok ? ['verified' => 1] : ['verified' => 0, 'reason' => $result->value];

        $response = new RedirectResponse(rtrim($this->frontendUrl, '/').'/connexion?'.http_build_query($query));
        $response->headers->set('Referrer-Policy', 'no-referrer'); // le jeton est dans l'URL

        return $response;
    }

    // Variante JSON (si le React gère lui-même une page de confirmation) : { "token": "…" }
    #[Route('/verify-email', name: 'api_verify_email_json', methods: ['POST'])]
    public function verifyJson(Request $request): JsonResponse
    {
        $token = $this->body($request)['token'] ?? '';
        $result = $this->verification->verify(is_string($token) ? $token : '');

        return match ($result) {
            VerificationResult::Ok => new JsonResponse(['verified' => true, 'message' => 'Adresse e-mail confirmée. Vous pouvez vous connecter.']),
            VerificationResult::Expired => throw new GoneHttpException('Ce lien a expiré. Demandez un nouvel e-mail de confirmation.'),
            VerificationResult::Invalid => throw new BadRequestHttpException('Lien de confirmation invalide.'),
        };
    }

    // Réponse identique que le compte existe ou non (pas d'énumération d'adresses)
    #[Route('/resend-verification', name: 'api_resend_verification', methods: ['POST'])]
    public function resend(Request $request): JsonResponse
    {
        $email = $this->body($request)['email'] ?? '';
        $email = is_string($email) ? mb_strtolower(trim($email)) : '';
        $this->throttle($this->resendLimiter, ($request->getClientIp() ?? 'unknown').'|'.$email);

        $user = $email !== '' ? $this->users->findOneByEmail($email) : null;
        if ($user !== null && !$user->isVerified()) {
            $this->verification->issueAndSend($user);
        }

        return new JsonResponse(['message' => 'Si un compte non confirmé existe pour cette adresse, un nouvel e-mail vient d’être envoyé.'], 202);
    }

    // « Mot de passe oublié » : { "email": "…" } -> réponse identique que le compte existe ou non
    #[Route('/forgot-password', name: 'api_forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request): JsonResponse
    {
        $email = $this->body($request)['email'] ?? '';
        $email = is_string($email) ? mb_strtolower(trim($email)) : '';
        $this->throttle($this->resetLimiter, ($request->getClientIp() ?? 'unknown').'|'.$email);

        if ($email !== '' && mb_strlen($email) <= 180) {
            // La page de réinitialisation s'ouvre dans la langue du site (le React envoie Accept-Language : fr ou en)
            $this->passwordReset->request($email, $request->getPreferredLanguage(['fr', 'en']) ?? 'fr');
        }

        return new JsonResponse(['message' => 'Si un compte existe pour cette adresse, un e-mail avec un lien de réinitialisation vient d’être envoyé (valable 1 heure).'], 202);
    }

    // Lien reçu par e-mail : { "token": "…", "password": "…", "confirm": "…" } ; ferme toutes les sessions ouvertes
    #[Route('/reset-password', name: 'api_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request): JsonResponse
    {
        $this->throttle($this->resetLimiter, ($request->getClientIp() ?? 'unknown').'|reset');

        $body = $this->body($request);
        $token = is_string($body['token'] ?? null) ? $body['token'] : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        if (mb_strlen($password) < 8 || mb_strlen($password) > 200) {
            throw new ValidationFailedException(['password' => 'Le mot de passe doit contenir 8 caractères minimum.']);
        }
        if (array_key_exists('confirm', $body) && $body['confirm'] !== $password) {
            throw new ValidationFailedException(['confirm' => 'Les mots de passe ne correspondent pas.']);
        }

        return match ($this->passwordReset->reset($token, $password)) {
            VerificationResult::Ok => new JsonResponse(['message' => 'Mot de passe modifié. Vous pouvez vous connecter.']),
            VerificationResult::Expired => throw new GoneHttpException('Ce lien a expiré. Demandez un nouveau lien de réinitialisation.'),
            VerificationResult::Invalid => throw new BadRequestHttpException('Lien de réinitialisation invalide ou déjà utilisé.'),
        };
    }

    // Géré par le pare-feu (json_login) : { email, password } -> { token, expiresAt, user }
    #[Route('/login', name: 'api_login', methods: ['POST'])]
    public function login(): never
    {
        throw new BadRequestHttpException('Envoyez { "email": "…", "password": "…" } en JSON (Content-Type: application/json).');
    }

    #[Route('/me', name: 'api_me', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return new JsonResponse($user->toArray());
    }

    #[Route('/logout', name: 'api_logout', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function logout(Request $request): Response
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            $this->tokens->revoke(substr($header, 7));
        }

        return new Response(status: 204);
    }
}
