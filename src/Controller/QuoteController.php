<?php

namespace App\Controller;

use App\Dto\QuoteInput;
use App\Entity\QuoteRequest;
use App\Entity\QuoteStatus;
use App\Repository\QuoteRequestRepository;
use App\Service\ApiValidator;
use App\Service\AppMailer;
use App\Service\QuoteService;
use App\Service\StripeGateway;
use App\Service\ValidationFailedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Devis de fresque : demande publique (formulaire + photos), page du devis pour le client (clé dans l'adresse), gestion dans le back-office.
#[Route('/api/quotes')]
final class QuoteController extends ApiController
{
    private const THANKS = 'Merci ! Votre demande de devis est bien envoyée. Vous recevez un e-mail de confirmation et je vous réponds en général sous 3 jours ouvrés.';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QuoteRequestRepository $quotes,
        private readonly ApiValidator $validator,
        private readonly QuoteService $service,
        private readonly AppMailer $mailer,
        private readonly StripeGateway $stripe,
        #[Autowire(service: 'limiter.quote')] private readonly RateLimiterFactory $quoteLimiter,
        #[Autowire(service: 'limiter.order')] private readonly RateLimiterFactory $checkoutLimiter,
        #[Autowire('%env(CONTACT_EMAIL)%')] private readonly string $shopEmail,
    ) {
    }

    // ---------------------------------------------------------------- public

    // Formulaire « Demander un devis » (multipart/form-data) : champs de QuoteInput + photos[] (4 maximum, 8 Mo chacune)
    #[Route('', name: 'api_quotes_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->throttle($this->quoteLimiter, $request->getClientIp() ?? 'unknown');

        $input = QuoteInput::fromArray($request->request->all());
        // Champ piège rempli = robot : on répond « succès » sans rien enregistrer ni envoyer
        if ($input->website !== '') {
            return new JsonResponse(['message' => self::THANKS], 201);
        }
        $this->validator->check($input);

        $raw = $request->files->get('photos');
        $files = array_values(array_filter(\is_array($raw) ? $raw : ($raw ? [$raw] : [])));
        $this->service->checkPhotos($files);
        $urls = $this->service->storePhotos($files);

        $quote = (new QuoteRequest())->setRequest(
            $input->name, $input->email, $input->phone !== '' ? $input->phone : null, $input->lang, $input->projectType,
            $input->widthCm, $input->heightCm, $input->location !== '' ? $input->location : null, $input->description,
            $input->budget, $input->desiredBy !== '' ? $input->desiredBy : null, $urls,
        );
        $this->em->persist($quote);
        $this->em->flush();

        // Les e-mails ne bloquent jamais la demande : elle est déjà enregistrée, un échec est journalisé
        $this->mailer->sendQuoteNotification($quote, $this->shopEmail);
        $this->mailer->sendQuoteAck($quote);

        return new JsonResponse(['reference' => $quote->getReference(), 'message' => self::THANKS], 201);
    }

    // Page du devis (lien reçu par e-mail) : la clé de 48 caractères, imprévisible, tient lieu de mot de passe.
    #[Route('/{token}', name: 'api_quotes_public', requirements: ['token' => '[a-f0-9]{48}'], methods: ['GET'])]
    public function publicQuote(string $token, Request $request): JsonResponse
    {
        $this->throttle($this->checkoutLimiter, ($request->getClientIp() ?? 'unknown').'|quote');
        $quote = $this->quotes->findOneBy(['payToken' => $token]) ?? throw new NotFoundHttpException();
        $this->service->syncDeposit($quote); // si le webhook de Stripe tarde, on vérifie directement

        return new JsonResponse($quote->toPublicArray() + [
            'payable' => $quote->getStatus() === QuoteStatus::Quoted && ($quote->depositCents() ?? 0) >= StripeGateway::MIN_CENTS
                && $quote->getDepositPaidAt() === null && $this->stripe->isEnabled(),
        ]);
    }

    // Bouton « Régler l'acompte » : renvoie l'adresse de la page de paiement Stripe
    #[Route('/{token}/checkout', name: 'api_quotes_checkout', requirements: ['token' => '[a-f0-9]{48}'], methods: ['POST'])]
    public function checkout(string $token, Request $request): JsonResponse
    {
        $this->throttle($this->checkoutLimiter, ($request->getClientIp() ?? 'unknown').'|quote');
        $quote = $this->quotes->findOneBy(['payToken' => $token]) ?? throw new NotFoundHttpException();

        return new JsonResponse(['checkoutUrl' => $this->service->createDepositCheckout($quote)]);
    }

    // ----------------------------------------------------------- back-office

    // ?status=nouvelle &q=… &page=1 &perPage=10
    #[Route('', name: 'api_quotes_index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(Request $request): JsonResponse
    {
        return $this->paginate($this->quotes->search($request->query->get('status'), $request->query->get('q')), $request, fn (QuoteRequest $q) => $q->toArray(), 10);
    }

    // { status?, adminNote?, amount?, depositPercent?, quoteMessage? } : modifications sans envoi au client
    #[Route('/{ref}', name: 'api_quotes_update', requirements: ['ref' => 'DEV-\d+'], methods: ['PATCH', 'PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(string $ref, Request $request): JsonResponse
    {
        $quote = $this->find($ref);
        $body = $this->body($request);
        $errors = [];

        if (\array_key_exists('status', $body)) {
            $status = \is_string($body['status']) ? QuoteStatus::tryFrom($body['status']) : null;
            $status === null ? $errors['status'] = 'Statut invalide.' : $quote->setStatus($status);
        }
        if (\array_key_exists('adminNote', $body)) {
            $note = \is_string($body['adminNote']) ? trim($body['adminNote']) : '';
            mb_strlen($note) > 3000 ? $errors['adminNote'] = 'Note trop longue (3000 caractères maximum).' : $quote->setAdminNote($note !== '' ? $note : null);
        }
        if (\array_key_exists('amount', $body) || \array_key_exists('depositPercent', $body) || \array_key_exists('quoteMessage', $body)) {
            try {
                [$cents, $percent, $message] = $this->quoteFields($body, $quote);
                $quote->setQuote($cents, $percent, $message);
            } catch (ValidationFailedException $e) {
                $errors += $e->violations;
            }
        }
        if ($errors !== []) {
            throw new ValidationFailedException($errors);
        }
        $this->em->flush();

        return new JsonResponse($quote->toArray());
    }

    // { amount, depositPercent, quoteMessage } : fixe le devis, l'envoie au client par e-mail (avec le lien de paiement de l'acompte)
    #[Route('/{ref}/send', name: 'api_quotes_send', requirements: ['ref' => 'DEV-\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function send(string $ref, Request $request): JsonResponse
    {
        $quote = $this->find($ref);
        if ($quote->getDepositPaidAt() !== null) {
            throw new ValidationFailedException(['amount' => 'L’acompte est déjà réglé : le devis ne peut plus être modifié.']);
        }
        [$cents, $percent, $message] = $this->quoteFields($this->body($request), $quote);
        $deposit = (int) round($cents * $percent / 100);
        if ($percent > 0 && $deposit < StripeGateway::MIN_CENTS) {
            throw new ValidationFailedException(['depositPercent' => 'Acompte trop faible (minimum 0,50 €).']);
        }
        if ($percent > 0 && !$this->stripe->isEnabled()) {
            throw new ValidationFailedException(['depositPercent' => 'Le paiement en ligne n’est pas activé (clé Stripe manquante) : mettez l’acompte à 0 %.']);
        }

        $quote->setQuote($cents, $percent, $message)->markQuoteSent(bin2hex(random_bytes(24)));
        $this->em->flush();
        $this->mailer->sendQuote($quote);

        return new JsonResponse($quote->toArray());
    }

    // Suppression (RGPD) : la demande et ses photos
    #[Route('/{ref}', name: 'api_quotes_delete', requirements: ['ref' => 'DEV-\d+'], methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(string $ref): Response
    {
        $this->service->purge([$this->find($ref)]);

        return new Response(status: 204);
    }

    /**
     * Montant (en euros, TTC), pourcentage d'acompte (0 à 100) et message : valeurs actuelles du devis par défaut.
     *
     * @return array{0: int, 1: int, 2: ?string} centimes, pourcentage, message
     */
    private function quoteFields(array $body, QuoteRequest $quote): array
    {
        $errors = [];
        $amount = $body['amount'] ?? ($quote->getAmountCents() === null ? null : $quote->getAmountCents() / 100);
        if (!is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 100000) {
            $errors['amount'] = 'Montant TTC invalide (de 0,01 à 100 000 €).';
        }
        $percent = $body['depositPercent'] ?? $quote->getDepositPercent();
        if (!is_numeric($percent) || (int) $percent != $percent || (int) $percent < 0 || (int) $percent > 100) {
            $errors['depositPercent'] = 'Pourcentage entier de 0 à 100.';
        }
        $message = $body['quoteMessage'] ?? $quote->getQuoteMessage();
        $message = \is_string($message) ? trim($message) : '';
        if (mb_strlen($message) > 2000) {
            $errors['quoteMessage'] = 'Message trop long (2000 caractères maximum).';
        }
        if ($errors !== []) {
            throw new ValidationFailedException($errors);
        }

        return [(int) round((float) $amount * 100), (int) $percent, $message !== '' ? $message : null];
    }

    private function find(string $ref): QuoteRequest
    {
        return $this->quotes->find((int) substr($ref, 4) - QuoteRequest::REF_OFFSET) ?? throw new NotFoundHttpException();
    }
}
