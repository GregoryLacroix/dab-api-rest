<?php

namespace App\Service;

use App\Entity\Product;
use App\Entity\StockAlert;
use App\Repository\StockAlertRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

// Alerte « prévenez-moi » quand un produit épuisé redevient disponible
final class StockAlertService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockAlertRepository $alerts,
        private readonly AppMailer $mailer,
    ) {
    }

    /** Enregistre l'inscription (silencieux si l'adresse est déjà inscrite pour ce produit). */
    public function subscribe(Product $product, string $email, string $lang): void
    {
        if ($product->getStock() > 0 || !$product->isPublished()) {
            throw new ValidationFailedException(['product' => $lang === 'en' ? 'This product is available: you can order it now.' : 'Ce produit est disponible : vous pouvez le commander.']);
        }
        if ($this->alerts->findOneBy(['productId' => $product->getId(), 'email' => mb_strtolower($email)]) !== null) {
            return;
        }
        try {
            $this->em->persist(new StockAlert($product->getId(), $email, $lang));
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // double clic : déjà inscrit
        }
    }

    /** À appeler quand le stock d'un produit passe de 0 à plus de 0 : prévient les inscrits, puis supprime leurs alertes. */
    public function notifyRestocked(Product $product): int
    {
        if ($product->getStock() <= 0 || !$product->isPublished()) {
            return 0;
        }
        $sent = 0;
        foreach ($this->alerts->findBy(['productId' => $product->getId()]) as $alert) {
            if ($this->alerts->claim($alert)) {
                $this->mailer->sendBackInStock($product, $alert->getEmail(), $alert->getLang());
                ++$sent;
            }
        }

        return $sent;
    }
}
