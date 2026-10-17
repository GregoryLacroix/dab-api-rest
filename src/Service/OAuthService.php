<?php

namespace App\Service;

use App\Entity\OAuthIdentity;
use App\Entity\User;
use App\Repository\OAuthIdentityRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// Connexion avec un compte Google / Facebook : OAuth 2.0 « code d'autorisation » (avec PKCE pour Google).
// Le site ne reçoit JAMAIS le mot de passe du compte Google/Facebook : seulement un identifiant stable, l'e-mail et le nom.
final class OAuthService
{
    /** Fournisseurs gérés (valeurs acceptées dans l'adresse /api/auth/oauth/{provider}/…) */
    public const PROVIDERS = ['google', 'facebook'];

    /** @var array<string, array<string, mixed>> */
    private array $config;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly OAuthIdentityRepository $identities,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%env(API_PUBLIC_URL)%')] private readonly string $apiUrl,
        #[Autowire('%env(GOOGLE_CLIENT_ID)%')] string $googleId,
        #[Autowire('%env(GOOGLE_CLIENT_SECRET)%')] string $googleSecret,
        #[Autowire('%env(FACEBOOK_APP_ID)%')] string $facebookId,
        #[Autowire('%env(FACEBOOK_APP_SECRET)%')] string $facebookSecret,
        #[Autowire('%env(FACEBOOK_GRAPH_VERSION)%')] string $facebookVersion,
    ) {
        $graph = 'https://graph.facebook.com/'.trim($facebookVersion ?: 'v23.0', '/');
        $this->config = [
            'google' => [
                'label' => 'Google',
                'id' => trim($googleId), 'secret' => trim($googleSecret),
                'authUrl' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'tokenUrl' => 'https://oauth2.googleapis.com/token',
                'profileUrl' => 'https://openidconnect.googleapis.com/v1/userinfo',
                'scope' => 'openid email profile',
                'pkce' => true,
                // Google indique si l'adresse est vérifiée (email_verified) : on peut alors la rattacher à un compte existant
                'trustEmail' => true,
                'extra' => ['prompt' => 'select_account'],
            ],
            'facebook' => [
                'label' => 'Facebook',
                'id' => trim($facebookId), 'secret' => trim($facebookSecret),
                'authUrl' => 'https://www.facebook.com/'.trim($facebookVersion ?: 'v23.0', '/').'/dialog/oauth',
                'tokenUrl' => $graph.'/oauth/access_token',
                'profileUrl' => $graph.'/me',
                'scope' => 'email,public_profile',
                'pkce' => false,
                // Facebook ne garantit pas que l'e-mail est vérifié : jamais de rattachement automatique à un compte existant,
                // et un nouveau compte doit confirmer son adresse par e-mail
                'trustEmail' => false,
                'extra' => [],
            ],
        ];
    }

    /** @return array<int, array{id: string, label: string}> fournisseurs configurés (clés renseignées dans .env.local) */
    public function enabledProviders(): array
    {
        $out = [];
        foreach ($this->config as $id => $c) {
            if ($c['id'] !== '' && $c['secret'] !== '') {
                $out[] = ['id' => $id, 'label' => $c['label']];
            }
        }

        return $out;
    }

    public function isEnabled(string $provider): bool
    {
        return \in_array($provider, array_column($this->enabledProviders(), 'id'), true);
    }

    public function usesPkce(string $provider): bool
    {
        return $this->config[$provider]['pkce'] === true;
    }

    // ---- Départ : adresse du fournisseur vers laquelle rediriger le navigateur

    public function authorizeUrl(string $provider, string $state, ?string $codeChallenge): string
    {
        $c = $this->config[$provider];
        $params = [
            'client_id' => $c['id'],
            'redirect_uri' => $this->redirectUri($provider),
            'response_type' => 'code',
            'scope' => $c['scope'],
            'state' => $state,
        ] + $c['extra'];
        if ($codeChallenge !== null) {
            $params['code_challenge'] = $codeChallenge;
            $params['code_challenge_method'] = 'S256';
        }

        return $c['authUrl'].'?'.http_build_query($params, '', '&', \PHP_QUERY_RFC3986);
    }

    public function redirectUri(string $provider): string
    {
        return rtrim($this->apiUrl, '/').'/api/auth/oauth/'.$provider.'/callback';
    }

    // ---- Mémoire de l'aller-retour : cookie signé de 10 minutes (l'API n'a pas de session)

    /** @return array{0: string, 1: string} [valeur du cookie, state] */
    public function newState(string $provider, string $lang, ?string $verifier): array
    {
        $state = bin2hex(random_bytes(16));
        $payload = self::b64(json_encode(['p' => $provider, 's' => $state, 'v' => $verifier, 'l' => $lang, 'e' => time() + 600], \JSON_THROW_ON_ERROR));

        return [$payload.'.'.$this->sign($payload), $state];
    }

    /** @return array{p: string, s: string, v: ?string, l: string, e: int}|null null si absent, modifié ou expiré */
    public function readState(string $cookie): ?array
    {
        $parts = explode('.', $cookie);
        if (\count($parts) !== 2 || !hash_equals($this->sign($parts[0]), $parts[1])) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (!\is_array($data) || !isset($data['p'], $data['s'], $data['l'], $data['e']) || $data['e'] < time()) {
            return null;
        }

        return $data + ['v' => null];
    }

    public static function newVerifier(): string
    {
        return self::b64(random_bytes(32));
    }

    public static function challenge(string $verifier): string
    {
        return self::b64(hash('sha256', $verifier, true));
    }

    // ---- Retour : échange du code contre le profil de la personne

    /** @return array{subject: string, email: string, emailVerified: bool, name: string} */
    public function fetchProfile(string $provider, string $code, ?string $verifier): array
    {
        $c = $this->config[$provider];
        try {
            $body = [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri($provider),
                'client_id' => $c['id'],
                'client_secret' => $c['secret'],
            ];
            if ($verifier !== null) {
                $body['code_verifier'] = $verifier;
            }
            $token = $this->http->request('POST', $c['tokenUrl'], ['body' => $body, 'timeout' => 10])->toArray();
            $access = $token['access_token'] ?? null;
            if (!\is_string($access) || $access === '') {
                throw new OAuthFailure('failed');
            }

            $profile = $provider === 'google'
                ? $this->http->request('GET', $c['profileUrl'], ['auth_bearer' => $access, 'timeout' => 10])->toArray()
                : $this->http->request('GET', $c['profileUrl'], ['query' => ['fields' => 'id,name,email', 'access_token' => $access], 'timeout' => 10])->toArray();
        } catch (OAuthFailure $e) {
            throw $e;
        } catch (\Throwable) {
            throw new OAuthFailure('failed'); // détail volontairement non exposé (le contrôleur journalise)
        }

        $subject = (string) ($profile[$provider === 'google' ? 'sub' : 'id'] ?? '');
        if ($subject === '' || \strlen($subject) > 191) {
            throw new OAuthFailure('failed');
        }

        return [
            'subject' => $subject,
            'email' => \is_string($profile['email'] ?? null) ? $profile['email'] : '',
            // Google renvoie un booléen (ou la chaîne "true")
            'emailVerified' => filter_var($profile['email_verified'] ?? false, \FILTER_VALIDATE_BOOLEAN),
            'name' => \is_string($profile['name'] ?? null) ? $profile['name'] : '',
        ];
    }

    /**
     * Trouve ou crée le compte du site correspondant à ce profil.
     *  1. identité déjà connue -> son compte ;
     *  2. e-mail déjà inscrit : rattachement seulement si le fournisseur garantit l'adresse (Google), sinon refus « account_exists » ;
     *  3. sinon création d'un compte sans mot de passe (confirmé d'emblée si l'adresse est garantie par le fournisseur,
     *     sinon l'appelant envoie l'e-mail de confirmation habituel).
     *
     * @param array{subject: string, email: string, emailVerified: bool, name: string} $profile
     *
     * @throws OAuthFailure
     */
    public function resolveUser(string $provider, array $profile): User
    {
        $known = $this->identities->findOneBy(['provider' => $provider, 'subject' => $profile['subject']]);
        if ($known !== null) {
            return $known->getUser();
        }

        $email = mb_strtolower(trim($profile['email']));
        if ($email === '' || !filter_var($email, \FILTER_VALIDATE_EMAIL) || \strlen($email) > 180) {
            throw new OAuthFailure('email_missing');
        }
        $trusted = $this->config[$provider]['trustEmail'] === true && $profile['emailVerified'] === true;

        $user = $this->users->findOneByEmail($email);
        if ($user !== null) {
            if (!$trusted) {
                throw new OAuthFailure('account_exists');
            }
            if (!$user->isVerified()) {
                // Compte jamais confirmé : quelqu'un a pu l'ouvrir avec cette adresse (et SON mot de passe) avant son vrai propriétaire.
                // Le vrai propriétaire vient de prouver son identité via Google : on supprime donc ce mot de passe.
                $user->setPassword('')->setVerified(true)->setVerificationHash(null)->setVerificationExpiresAt(null);
            }
        } else {
            $user = (new User())->setEmail($email)->setName($this->cleanName($profile['name'], $email))->setRoles([])
                ->setPassword('')->setVerified($trusted);
            $this->em->persist($user);
        }
        $this->em->persist(new OAuthIdentity($user, $provider, $profile['subject']));
        $this->em->flush();

        return $user;
    }

    // ---------------------------------------------------------------------------------------------------------------------

    private function cleanName(string $name, string $email): string
    {
        $name = trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $name));
        if ($name === '') {
            $name = (string) strstr($email, '@', true);
        }

        return mb_substr($name, 0, 120);
    }

    private function sign(string $payload): string
    {
        return self::b64(hash_hmac('sha256', 'oauth-state|'.$payload, $this->secret, true));
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
