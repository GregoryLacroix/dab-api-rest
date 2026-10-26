<?php

namespace App\Service;

use App\Entity\QuoteRequest;
use App\Entity\QuoteStatus;
use App\Repository\QuoteRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

// Demandes de devis : photos du mur, paiement de l'acompte par Stripe, purge.
final class QuoteService
{
    public const MAX_PHOTOS = 4;
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QuoteRequestRepository $quotes,
        private readonly StripeGateway $stripe,
        private readonly AppMailer $mailer,
        private readonly ImageOptimizer $optimizer,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/public/uploads/quotes')] private readonly string $dir,
        #[Autowire('%env(API_PUBLIC_URL)%')] private readonly string $apiUrl,
        #[Autowire('%env(CONTACT_EMAIL)%')] private readonly string $shopEmail,
    ) {
    }

    /** @param array<int, mixed> $files @throws ValidationFailedException */
    public function checkPhotos(array $files): void
    {
        if (\count($files) > self::MAX_PHOTOS) {
            throw new ValidationFailedException(['photos' => 'Quatre photos au maximum.']);
        }
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                throw new ValidationFailedException(['photos' => 'Une photo n’a pas pu être envoyée. Réessayez avec un fichier plus léger.']);
            }
            if ($file->getSize() > self::MAX_BYTES) {
                throw new ValidationFailedException(['photos' => 'Une photo dépasse 8 Mo.']);
            }
            if (!isset(self::TYPES[(string) $file->getMimeType()])) {
                throw new ValidationFailedException(['photos' => 'Formats acceptés : JPEG, PNG ou WebP.']);
            }
        }
    }

    /**
     * Enregistre les photos du mur (optimisées si possible) et renvoie leurs adresses.
     *
     * @param UploadedFile[] $files
     * @return string[]
     */
    public function storePhotos(array $files): array
    {
        $base = rtrim($this->apiUrl, '/').'/uploads/quotes/';
        $urls = [];
        foreach ($files as $file) {
            $ext = self::TYPES[(string) $file->getMimeType()] ?? 'jpg';
            if ($this->optimizer->isAvailable()) {
                try {
                    $urls[] = $base.$this->optimizer->process($file->getPathname(), $this->dir, false)['name'];
                    continue;
                } catch (\Throwable $e) {
                    $this->logger->warning('Photo de devis non optimisée : '.$e->getMessage());
                }
            }
            $name = bin2hex(random_bytes(16)).'.'.$ext;
            $file->move($this->dir, $name);
            $urls[] = $base.$name;
        }

        return $urls;
    }

    /** Retour de Stripe pour une session d'acompte (webhook). Renvoie false si la session n'est celle d'aucun devis. */
    public function handleSession(array $session): bool
    {
        $id = $session['id'] ?? null;
        $quote = \is_string($id) ? $this->quotes->findOneBy(['depositSessionId' => $id]) : null;
        // Repli : si le client a ouvert deux pages de paiement, la première payée garde sa trace grâce aux métadonnées
        if ($quote === null && \is_string($session['metadata']['quote_id'] ?? null) && ctype_digit($session['metadata']['quote_id'])) {
            $quote = $this->quotes->find((int) $session['metadata']['quote_id']);
        }
        if ($quote === null) {
            return false;
        }
        if (($session['status'] ?? null) === 'complete' && \in_array($session['payment_status'] ?? null, ['paid', 'no_payment_required'], true)) {
            $this->markDepositPaid($quote, $session);
        }

        return true;
    }

    /** Rattrape un webhook en retard : demande à Stripe où en est la page de paiement de l'acompte. */
    public function syncDeposit(QuoteRequest $quote): void
    {
        if (!$this->stripe->isEnabled() || $quote->getDepositSessionId() === null || $quote->getDepositPaidAt() !== null) {
            return;
        }
        try {
            $this->handleSession($this->stripe->retrieveSession($quote->getDepositSessionId()));
        } catch (\Throwable $e) {
            $this->logger->warning('Stripe : session d’acompte illisible ('.$e->getMessage().')', ['quote' => $quote->getReference()]);
        }
    }

    /** Crée la page de paiement de l'acompte et renvoie son adresse. */
    public function createDepositCheckout(QuoteRequest $quote): string
    {
        $cents = $quote->depositCents() ?? 0;
        if ($quote->getStatus() !== QuoteStatus::Quoted || $quote->getAmountCents() === null || $cents < StripeGateway::MIN_CENTS) {
            throw new ConflictHttpException('Aucun acompte n’est à régler pour ce devis.');
        }
        if ($quote->getDepositPaidAt() !== null) {
            throw new ConflictHttpException('L’acompte de ce devis est déjà réglé. Merci !');
        }
        if (!$this->stripe->isEnabled()) {
            throw new ServiceUnavailableHttpException(null, 'Le paiement en ligne n’est pas disponible pour le moment. Répondez simplement à l’e-mail du devis.');
        }
        try {
            $session = $this->stripe->createQuoteDeposit($quote, $cents);
        } catch (\Throwable $e) {
            $this->logger->error('Stripe : page d’acompte impossible à créer ('.$e->getMessage().')', ['quote' => $quote->getReference()]);
            throw new ServiceUnavailableHttpException(30, 'Le paiement en ligne est momentanément indisponible. Réessayez dans quelques instants.');
        }
        $quote->setDepositSessionId($session['id']);
        $this->em->flush();

        return $session['url'];
    }

    /**
     * Supprime des demandes (RGPD) : leurs photos et leurs données.
     *
     * @param QuoteRequest[] $quotes
     */
    public function purge(array $quotes): void
    {
        foreach ($quotes as $quote) {
            foreach ($quote->getPhotos() as $url) {
                $file = basename((string) parse_url((string) $url, \PHP_URL_PATH));
                if (preg_match('~^[a-f0-9]{32}(-opt)?\.(webp|jpg|png)$~', $file) && is_file($this->dir.'/'.$file)) {
                    @unlink($this->dir.'/'.$file);
                }
            }
            $this->em->remove($quote);
        }
        $this->em->flush();
    }

    private function markDepositPaid(QuoteRequest $quote, array $session): void
    {
        if (!$this->quotes->claimDepositPaid($quote)) {
            return; // déjà enregistré (webhook ou page de retour)
        }
        $this->em->refresh($quote);
        $intent = $session['payment_intent'] ?? null;
        $quote->setDepositPaymentIntentId(\is_array($intent) ? ($intent['id'] ?? null) : (\is_string($intent) ? $intent : null));
        $this->em->flush();

        $this->mailer->sendDepositReceived($quote, $this->shopEmail);
    }
}
