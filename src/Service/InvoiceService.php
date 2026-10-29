<?php

namespace App\Service;

use App\Entity\Order;
use App\Entity\Setting;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

// Factures des commandes payées : numérotation continue + PDF (bibliothèque FPDF, sans dépendance).
// La numérotation d'une facture doit être chronologique et sans trou (règle comptable) : le numéro est donc attribué
// à la confirmation du paiement (PaymentService), jamais pour une commande abandonnée ou annulée avant paiement.
final class InvoiceService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SiteContent $content,
    ) {
    }

    /** Attribue le prochain numéro de l'année (FA-2026-0001…) s'il n'y en a pas encore ; renvoie le numéro. */
    public function ensureNumber(Order $order): string
    {
        if ($order->getInvoiceNumber() !== null) {
            return $order->getInvoiceNumber();
        }
        $year = ($order->getPaidAt() ?? $order->getCreatedAt())->format('Y');
        $this->assign('FA', 'invoice_counter_', $year, fn (string $n) => $order->setInvoice($n, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));

        return (string) $order->getInvoiceNumber();
    }

    /** Attribue le prochain numéro d'AVOIR de l'année (AV-2026-0001…), série distincte de celle des factures ; renvoie le numéro. */
    public function ensureCreditNoteNumber(Order $order): string
    {
        if ($order->getCreditNoteNumber() !== null) {
            return $order->getCreditNoteNumber();
        }
        $this->ensureNumber($order); // l'avoir renvoie à une facture : elle doit exister
        $year = ($order->getRefundedAt() ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y');
        $this->assign('AV', 'credit_counter_', $year, fn (string $n) => $order->setCreditNote($n, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));

        return (string) $order->getCreditNoteNumber();
    }

    /** Contenu du fichier PDF de la facture (attribue le numéro si besoin). */
    public function render(Order $order): string
    {
        return $this->build($order, false);
    }

    /** PDF de l'avoir d'une commande remboursée (attribue les numéros si besoin). */
    public function renderCreditNote(Order $order): string
    {
        return $this->build($order, true);
    }

    /**
     * Compteur par année et par série, verrouillé le temps de la transaction : deux opérations simultanées n'obtiennent jamais le même
     * numéro, et un échec d'enregistrement ne laisse pas de trou dans la série (règle comptable).
     *
     * @param callable(string): mixed $apply reçoit le numéro et l'inscrit sur la commande
     */
    private function assign(string $prefix, string $counterKey, string $year, callable $apply): void
    {
        $this->em->wrapInTransaction(function () use ($prefix, $counterKey, $year, $apply): void {
            $key = $counterKey.$year;
            $counter = $this->em->find(Setting::class, $key, LockMode::PESSIMISTIC_WRITE);
            if ($counter === null) {
                $counter = new Setting($key, ['last' => 0]);
                $this->em->persist($counter);
            }
            $next = (int) ($counter->getValue()['last'] ?? 0) + 1;
            $counter->setValue(['last' => $next]);
            $apply(sprintf('%s-%s-%04d', $prefix, $year, $next));
        });
    }

    /** Facture ($credit = false) ou avoir ($credit = true) : même mise en page, montants négatifs pour l'avoir. */
    private function build(Order $order, bool $credit): string
    {
        $invoiceNumber = $this->ensureNumber($order);
        $number = $credit ? $this->ensureCreditNoteNumber($order) : $invoiceNumber;
        $legal = $this->content->get()['legal'] ?? [];
        $when = $credit ? ($order->getCreditNotedAt() ?? $order->getRefundedAt() ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            : ($order->getPaidAt() ?? $order->getCreatedAt());
        $sign = $credit ? -1 : 1;
        $paris = new \DateTimeZone('Europe/Paris');

        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetMargins(18, 18, 18);
        $pdf->SetAutoPageBreak(true, 22);
        $pdf->SetTitle($this->t(($credit ? 'Avoir ' : 'Facture ').$number));
        $pdf->SetAuthor($this->t((string) (($legal['company'] ?? '') ?: 'Les galets d’Ambre')));
        $pdf->AddPage();

        // --- En-tête : vendeur à gauche, facture à droite
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->SetXY(18, 18);
        $pdf->Cell(110, 8, $this->t('Les galets d’Ambre'), 0, 0);
        $pdf->SetFont('Helvetica', 'B', 20);
        $pdf->Cell(0, 8, $credit ? 'AVOIR' : 'FACTURE', 0, 1, 'R');

        $pdf->SetFont('Helvetica', '', 9);
        $seller = array_values(array_filter([
            $this->field($legal, 'company').(($legal['status'] ?? '') !== '' ? ' ('.$legal['status'].')' : ''),
            $this->field($legal, 'address'),
            'SIRET : '.$this->field($legal, 'siret'),
            'E-mail : '.($this->field($legal, 'email', $this->content->get()['contact']['email'] ?? '')),
            ($legal['phone'] ?? '') !== '' ? 'Tél. : '.$legal['phone'] : null,
        ]));
        $y0 = $pdf->GetY() + 1;
        $pdf->SetXY(18, $y0);
        $pdf->MultiCell(100, 4.6, $this->t(implode("\n", $seller)), 0, 'L');
        $sellerEnd = $pdf->GetY();

        $pdf->SetXY(120, $y0);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 5, $this->t('N° '.$number), 0, 1, 'R');
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetX(120);
        $pdf->Cell(0, 4.6, $this->t('Date : '.$when->setTimezone($paris)->format('d/m/Y')), 0, 1, 'R');
        $pdf->SetX(120);
        $pdf->Cell(0, 4.6, $this->t('Commande : '.$order->getReference()), 0, 1, 'R');
        if ($credit) {
            $pdf->SetX(120);
            $pdf->Cell(0, 4.6, $this->t('Annule la facture '.$invoiceNumber), 0, 1, 'R');
        }

        // --- Client
        $pdf->SetY(max($sellerEnd, $pdf->GetY()) + 8);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(0, 5, $this->t('Facturé à'), 0, 1);
        $pdf->SetFont('Helvetica', '', 10);
        $customer = [$order->getCustomerName(), $order->getCustomerEmail()];
        $ship = $this->addressLines($order->getShippingAddress());
        $pdf->MultiCell(90, 5, $this->t(implode("\n", $customer)), 0, 'L');
        if ($ship !== []) {
            $pdf->Ln(1);
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->Cell(0, 5, $this->t('Livraison'), 0, 1);
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->MultiCell(90, 5, $this->t(implode("\n", $ship)), 0, 'L');
        }

        // --- Lignes
        $pdf->Ln(8);
        $x = 18;
        $w = ['name' => 92, 'qty' => 18, 'unit' => 32, 'total' => 32];
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetFillColor(238, 232, 220);
        $pdf->Cell($w['name'], 7, $this->t('Désignation'), 0, 0, 'L', true);
        $pdf->Cell($w['qty'], 7, $this->t('Qté'), 0, 0, 'R', true);
        $pdf->Cell($w['unit'], 7, $this->t('Prix unitaire TTC'), 0, 0, 'R', true);
        $pdf->Cell($w['total'], 7, $this->t('Total TTC'), 0, 1, 'R', true);

        $pdf->SetFont('Helvetica', '', 10);
        $itemsCents = 0;
        foreach ($order->getItems() as $item) {
            $qty = (int) $item['qty'];
            $unitCents = (int) round(((float) $item['price']) * 100);
            $lineCents = $unitCents * $qty;
            $itemsCents += $lineCents;
            $this->row($pdf, $x, $w, (string) $item['name'], (string) $qty, $this->eur($sign * $unitCents), $this->eur($sign * $lineCents));
        }
        // Frais de port éventuels : différence entre le total encaissé et le total des articles
        $shippingCents = $order->getTotalCents() - $itemsCents;
        if ($shippingCents > 0) {
            $this->row($pdf, $x, $w, 'Livraison', '1', $this->eur($sign * $shippingCents), $this->eur($sign * $shippingCents));
        }

        // --- Total
        $pdf->Ln(2);
        $pdf->SetDrawColor(160, 150, 130);
        $pdf->Line($x, $pdf->GetY(), $x + array_sum($w), $pdf->GetY());
        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->Cell($w['name'] + $w['qty'] + $w['unit'], 8, $this->t($credit ? 'Total TTC remboursé (euros)' : 'Total TTC (euros)'), 0, 0, 'R');
        $pdf->Cell($w['total'], 8, $this->t($this->eur($sign * $order->getTotalCents())), 0, 1, 'R');

        // --- Mentions
        $pdf->Ln(4);
        $pdf->SetFont('Helvetica', '', 9);
        $vat = trim((string) ($legal['vatNote'] ?? ''));
        if ($vat !== '') {
            $pdf->MultiCell(0, 4.6, $this->t($vat), 0, 'L');
        }
        if ($credit) {
            $paidLine = 'Avoir établi suite au remboursement intégral de la facture '.$invoiceNumber
                .' du '.($order->getPaidAt() ?? $order->getCreatedAt())->setTimezone($paris)->format('d/m/Y')
                .($order->getRefundedAt() !== null ? '. Remboursement effectué le '.$order->getRefundedAt()->setTimezone($paris)->format('d/m/Y').' sur le moyen de paiement d’origine.' : '.');
        } else {
            $paidLine = $order->getPaidAt() !== null
                ? 'Facture acquittée : payée le '.$order->getPaidAt()->setTimezone($paris)->format('d/m/Y').' par paiement en ligne sécurisé.'
                : 'Facture acquittée.';
        }
        $pdf->MultiCell(0, 4.6, $this->t($paidLine), 0, 'L');

        $pdf->SetY(-28);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(110, 100, 85);
        $pdf->MultiCell(0, 4, $this->t(
            'Les galets d’Ambre – '.$this->field($legal, 'company').' – SIRET '.$this->field($legal, 'siret')."\n".
            ($credit ? 'Cet avoir annule la facture '.$invoiceNumber.' pour le montant indiqué.' : 'Conservez cette facture : elle fait office de justificatif d’achat (garanties légales de conformité).')
        ), 0, 'C');

        return $pdf->Output('S');
    }

    public function fileName(Order $order): string
    {
        return 'facture-'.($order->getInvoiceNumber() ?? $order->getReference()).'.pdf';
    }

    public function creditNoteFileName(Order $order): string
    {
        return 'avoir-'.($order->getCreditNoteNumber() ?? $order->getReference()).'.pdf';
    }

    // ---------------------------------------------------------------------------------------------------------------------

    /** Une ligne de tableau dont la désignation peut passer sur plusieurs lignes. */
    private function row(\FPDF $pdf, float $x, array $w, string $name, string $qty, string $unit, string $total): void
    {
        $y = $pdf->GetY();
        $pdf->SetXY($x, $y);
        $pdf->MultiCell($w['name'], 6, $this->t($name), 0, 'L');
        $h = max(6, $pdf->GetY() - $y);
        $pdf->SetXY($x + $w['name'], $y);
        $pdf->Cell($w['qty'], $h, $this->t($qty), 0, 0, 'R');
        $pdf->Cell($w['unit'], $h, $this->t($unit), 0, 0, 'R');
        $pdf->Cell($w['total'], $h, $this->t($total), 0, 1, 'R');
        $pdf->SetY($y + $h);
    }

    private function eur(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ').' €';
    }

    /** Champ légal ; s'il n'est pas encore rempli dans le back-office, un repère visible plutôt qu'un blanc. */
    private function field(array $legal, string $key, string $fallback = ''): string
    {
        $value = trim((string) ($legal[$key] ?? ''));

        return $value !== '' ? $value : ($fallback !== '' ? $fallback : '[à compléter]');
    }

    /** @return string[] */
    private function addressLines(?array $a): array
    {
        if ($a === null) {
            return [];
        }
        $city = trim(($a['postalCode'] ?? '').' '.($a['city'] ?? ''));

        return array_values(array_filter([$a['name'] ?? null, $a['line1'] ?? null, $a['line2'] ?? null, $city, $a['state'] ?? null, $a['country'] ?? null],
            static fn ($l) => \is_string($l) && trim($l) !== ''));
    }

    /** FPDF (polices standard) attend du Windows-1252 : accents et « € » passent, les caractères exotiques deviennent « ? ». */
    private function t(string $utf8): string
    {
        return mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');
    }
}
