<?php

namespace App\Service;

use App\Entity\GiftCard;
use App\Repository\GiftCardRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

// Vente de cartes cadeau : la carte est créée « en attente », activée quand Stripe confirme le paiement (webhook ou page de retour),
// puis son code est envoyé par e-mail.
final class GiftCardService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GiftCardRepository $cards,
        private readonly StripeGateway $stripe,
        private readonly AppMailer $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{amount: int, buyerName: string, buyerEmail: string, recipientName: ?string, recipientEmail: ?string, message: ?string} $d amount en centimes
     * @return array{card: GiftCard, checkoutUrl: string}
     */
    public function purchase(array $d, string $lang): array
    {
        if (!$this->stripe->isEnabled()) {
            throw new ServiceUnavailableHttpException(null, $lang === 'en'
                ? 'Gift cards are not available for purchase online yet.'
                : 'L’achat de cartes cadeau en ligne n’est pas encore disponible.');
        }
        $card = (new GiftCard())->setBuyer($d['buyerName'], $d['buyerEmail'])->setRecipient($d['recipientName'], $d['recipientEmail'])
            ->setMessage($d['message'])->setLang($lang)->setInitialCents($d['amount'])->setCode($this->uniqueCode());
        $this->em->persist($card);
        $this->em->flush();

        try {
            $session = $this->stripe->createGiftCardCheckout($card);
        } catch (\Throwable $e) {
            $this->logger->error('Stripe : page de paiement de carte cadeau impossible ('.$e->getMessage().')');
            $this->em->remove($card);
            $this->em->flush();
            throw new ServiceUnavailableHttpException(30, $lang === 'en'
                ? 'Online payment is temporarily unavailable. Please try again in a few moments.'
                : 'Le paiement en ligne est momentanément indisponible. Réessayez dans quelques instants.');
        }
        $card->setStripeSessionId($session['id']);
        $this->em->flush();

        return ['card' => $card, 'checkoutUrl' => $session['url']];
    }

    /** Événement Stripe : renvoie false si la session n'est pas celle d'une carte cadeau. */
    public function handleSession(array $session): bool
    {
        $id = $session['id'] ?? null;
        $card = \is_string($id) ? $this->cards->findOneBy(['stripeSessionId' => $id]) : null;
        if ($card === null) {
            return false;
        }
        $state = $session['status'] ?? null;
        if ($state === 'complete' && \in_array($session['payment_status'] ?? null, ['paid', 'no_payment_required'], true)) {
            $this->activate($card);
        } elseif ($state === 'expired' && $card->getStatus() === GiftCard::PENDING) {
            $this->em->remove($card); // paiement abandonné : rien n'a été encaissé
            $this->em->flush();
        }

        return true;
    }

    /** Page de retour : interroge Stripe si le webhook tarde. */
    public function sync(GiftCard $card): void
    {
        if (!$this->stripe->isEnabled() || $card->getStripeSessionId() === null || $card->getStatus() !== GiftCard::PENDING) {
            return;
        }
        try {
            $this->handleSession($this->stripe->retrieveSession($card->getStripeSessionId()));
        } catch (\Throwable $e) {
            $this->logger->warning('Stripe : session de carte cadeau illisible ('.$e->getMessage().')');
        }
    }

    private function activate(GiftCard $card): void
    {
        // Passage atomique « en attente » -> « active » : un seul des deux chemins (webhook / retour) envoie les e-mails
        $won = 1 === $this->cards->createQueryBuilder('g')->update()
            ->set('g.status', ':a')->where('g.id = :id')->andWhere('g.status = :p')
            ->setParameter('a', GiftCard::ACTIVE)->setParameter('p', GiftCard::PENDING)->setParameter('id', $card->getId())
            ->getQuery()->execute();
        if (!$won) {
            return;
        }
        $this->em->refresh($card);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $card->setPaidAt($now)->setExpiresAt($now->modify(GiftCard::VALIDITY));
        $this->em->flush();

        $this->mailer->sendGiftCard($card, $card->getBuyerEmail(), $card->getBuyerName(), false);
        if ($card->getRecipientEmail() !== null && $card->getRecipientEmail() !== $card->getBuyerEmail()) {
            $this->mailer->sendGiftCard($card, $card->getRecipientEmail(), $card->getRecipientName() ?? $card->getRecipientEmail(), true);
        }
    }

    private function uniqueCode(): string
    {
        do {
            $code = GiftCard::generateCode();
        } while ($this->cards->findOneBy(['code' => $code]) !== null);

        return $code;
    }
}
