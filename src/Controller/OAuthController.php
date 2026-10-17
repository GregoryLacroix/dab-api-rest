<?php

namespace App\Controller;

use App\Service\ApiTokenService;
use App\Service\EmailVerificationService;
use App\Service\OAuthFailure;
use App\Service\OAuthService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

// Connexion avec Google / Facebook. Le navigateur fait deux sauts complets de page (pas d'appel AJAX) :
//   React -> GET /start -> fournisseur -> GET /callback -> React (/connexion/retour#token=…)
#[Route('/api/auth/oauth')]
final class OAuthController extends ApiController
{
    private const COOKIE = 'dab_oauth';
    private const COOKIE_PATH = '/api/auth/oauth';
    // Adresses du site React par langue (miroir de src/i18n/routes.js côté React)
    private const FRONT = [
        'fr' => ['login' => '/connexion', 'back' => '/connexion/retour'],
        'en' => ['login' => '/en/login', 'back' => '/en/login/callback'],
    ];

    public function __construct(
        private readonly OAuthService $oauth,
        private readonly ApiTokenService $tokens,
        private readonly EmailVerificationService $verification,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.oauth')] private readonly RateLimiterFactory $limiter,
        #[Autowire('%env(FRONTEND_URL)%')] private readonly string $frontendUrl,
        #[Autowire('%env(API_PUBLIC_URL)%')] private readonly string $apiUrl,
    ) {
    }

    // Boutons à afficher sur les pages Connexion / Inscription : uniquement les fournisseurs configurés
    #[Route('/providers', name: 'api_oauth_providers', methods: ['GET'])]
    public function providers(): JsonResponse
    {
        return new JsonResponse(['providers' => $this->oauth->enabledProviders()]);
    }

    #[Route('/{provider}/start', name: 'api_oauth_start', requirements: ['provider' => 'google|facebook'], methods: ['GET'])]
    public function start(string $provider, Request $request): Response
    {
        $this->throttle($this->limiter, $request->getClientIp() ?? 'unknown');
        $lang = $request->query->get('lang') === 'en' ? 'en' : 'fr';
        if (!$this->oauth->isEnabled($provider)) {
            return $this->back($lang, 'disabled');
        }

        $verifier = $this->oauth->usesPkce($provider) ? OAuthService::newVerifier() : null;
        [$cookie, $state] = $this->oauth->newState($provider, $lang, $verifier);
        $response = new RedirectResponse($this->oauth->authorizeUrl($provider, $state, $verifier === null ? null : OAuthService::challenge($verifier)));
        // Cookie de sécurité : 10 minutes, illisible par le JavaScript, envoyé seulement au retour du fournisseur (SameSite=Lax)
        $response->headers->setCookie(Cookie::create(self::COOKIE, $cookie, time() + 600, self::COOKIE_PATH, null, $this->secure(), true, false, Cookie::SAMESITE_LAX));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/{provider}/callback', name: 'api_oauth_callback', requirements: ['provider' => 'google|facebook'], methods: ['GET'])]
    public function callback(string $provider, Request $request): Response
    {
        $this->throttle($this->limiter, $request->getClientIp() ?? 'unknown');

        $state = $this->oauth->readState((string) $request->cookies->get(self::COOKIE, ''));
        $lang = $state['l'] ?? 'fr';
        // Le « state » du cookie doit être celui que le fournisseur nous renvoie (protège contre les connexions forcées)
        if ($state === null || $state['p'] !== $provider || !hash_equals($state['s'], (string) $request->query->get('state', ''))) {
            return $this->back($lang, 'invalid_state');
        }
        if ($request->query->has('error')) {
            return $this->back($lang, 'cancelled'); // la personne a refusé ou fermé la fenêtre du fournisseur
        }
        $code = (string) $request->query->get('code', '');
        if ($code === '') {
            return $this->back($lang, 'failed');
        }

        try {
            $profile = $this->oauth->fetchProfile($provider, $code, $state['v']);
            $user = $this->oauth->resolveUser($provider, $profile);
        } catch (OAuthFailure $e) {
            return $this->back($lang, $e->reason);
        } catch (\Throwable $e) {
            $this->logger->error('Connexion '.$provider.' impossible : '.$e->getMessage(), ['exception' => $e]);

            return $this->back($lang, 'failed');
        }

        if (!$user->isVerified()) {
            // Adresse non garantie par le fournisseur : on la fait confirmer comme pour une inscription classique
            $this->verification->issueAndSend($user);

            return $this->back($lang, 'verify_email');
        }

        $issued = $this->tokens->issue($user);
        // Le jeton est dans le FRAGMENT (#…) : il n'est jamais envoyé à un serveur ni écrit dans les journaux ; le React l'efface de l'adresse aussitôt lu
        $response = new RedirectResponse(rtrim($this->frontendUrl, '/').self::FRONT[$lang]['back'].'#token='.$issued['token']);
        $this->harden($response);

        return $response;
    }

    /** Retour au React avec un code d'erreur : /connexion?oauth=<raison> */
    private function back(string $lang, string $reason): RedirectResponse
    {
        $lang = isset(self::FRONT[$lang]) ? $lang : 'fr';
        $response = new RedirectResponse(rtrim($this->frontendUrl, '/').self::FRONT[$lang]['login'].'?'.http_build_query(['oauth' => $reason]));
        $this->harden($response);

        return $response;
    }

    private function harden(RedirectResponse $response): void
    {
        $response->headers->clearCookie(self::COOKIE, self::COOKIE_PATH, null, $this->secure(), true, Cookie::SAMESITE_LAX);
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store');
    }

    private function secure(): bool
    {
        return str_starts_with($this->apiUrl, 'https://');
    }
}
