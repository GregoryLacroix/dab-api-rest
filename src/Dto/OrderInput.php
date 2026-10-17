<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

// Corps attendu : { customer: { name, email }, items: [{ productId, qty }] }
// Les prix et le total sont TOUJOURS recalculés côté serveur (jamais lus dans la requête).
final class OrderInput
{
    public string $name = '';
    public string $email = '';
    /** @var array<int, array{productId: mixed, qty: mixed}> */
    public array $items = [];
    // Acceptation des conditions générales de vente (obligatoire pour commander)
    public bool $acceptTerms = false;

    public static function fromArray(array $d): self
    {
        $in = new self();
        $c = is_array($d['customer'] ?? null) ? $d['customer'] : [];
        $in->name = is_string($c['name'] ?? null) ? trim($c['name']) : '';
        $in->email = is_string($c['email'] ?? null) ? mb_strtolower(trim($c['email'])) : '';
        $in->acceptTerms = filter_var($d['acceptTerms'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $rows = is_array($d['items'] ?? null) ? array_values($d['items']) : [];
        foreach ($rows as $r) {
            $r = is_array($r) ? $r : [];
            $in->items[] = ['productId' => $r['productId'] ?? null, 'qty' => $r['qty'] ?? null];
        }

        return $in;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        $fail = fn (string $path, string $msg) => $context->buildViolation($msg)->atPath($path)->addViolation();

        if ($this->name === '') {
            $fail('customer.name', 'Saisissez votre nom.');
        } elseif (mb_strlen($this->name) > 120) {
            $fail('customer.name', 'Le nom est trop long (120 caractères maximum).');
        }
        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL) || strlen($this->email) > 180) {
            $fail('customer.email', 'Saisissez une adresse e-mail valide.');
        }
        if (!$this->acceptTerms) {
            $fail('acceptTerms', 'Veuillez accepter les conditions générales de vente pour commander.');
        }
        if ($this->items === []) {
            $fail('items', 'Votre panier est vide.');
        } elseif (count($this->items) > 50) {
            $fail('items', 'Trop d’articles dans la commande (50 maximum).');
        }
        foreach ($this->items as $i => $row) {
            if (!is_int($row['productId']) || $row['productId'] < 1) {
                $fail("items.$i.productId", 'Produit invalide.');
            }
            if (!is_int($row['qty']) || $row['qty'] < 1 || $row['qty'] > 99) {
                $fail("items.$i.qty", 'Quantité invalide (de 1 à 99).');
            }
        }
    }
}
