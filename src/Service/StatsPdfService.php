<?php

namespace App\Service;

// Export PDF des statistiques du tableau de bord (mêmes données que GET /api/admin/analytics), avec la bibliothèque FPDF des factures.
final class StatsPdfService
{
    private const INK = [31, 41, 55];
    private const ACCENT = [200, 137, 26];
    private const SAND = [242, 234, 220];
    private const GREY = [107, 114, 128];
    private const PALETTE = [[200, 137, 26], [31, 41, 55], [107, 143, 113], [180, 95, 95], [124, 141, 181], [163, 163, 163]];
    private const MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    private const DAYS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
    private const PERIOD_LABELS = ['7' => '7 derniers jours', '30' => '30 derniers jours', '90' => '90 derniers jours', '365' => '12 derniers mois', 'all' => 'Depuis le début'];

    public function render(array $d): string
    {
        $generated = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('d/m/Y à H:i');
        $pdf = new class($generated) extends \FPDF {
            public function __construct(private readonly string $generated)
            {
                parent::__construct('P', 'mm', 'A4');
            }

            public function Footer(): void
            {
                $this->SetY(-13);
                $this->SetFont('Helvetica', '', 8);
                $this->SetTextColor(107, 114, 128);
                $this->Cell(0, 5, mb_convert_encoding('Les galets d’Ambre · Rapport généré le '.$this->generated, 'Windows-1252', 'UTF-8'), 0, 0, 'L');
                $this->Cell(0, 5, 'Page '.$this->PageNo().'/{nb}', 0, 0, 'R');
            }
        };
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AliasNbPages();
        $pdf->SetTitle($this->t('Statistiques – Les galets d’Ambre'));
        $pdf->SetAuthor($this->t('Les galets d’Ambre'));
        $pdf->AddPage();

        $this->header($pdf, $d);
        $this->kpis($pdf, $d['kpis']);
        $this->revenue($pdf, $d);
        $this->topProducts($pdf, $d['topProducts']);
        $this->categories($pdf, $d['categories']);
        $this->weekdaysAndStatuses($pdf, $d);
        $this->quotesReviewsStock($pdf, $d);

        return $pdf->Output('S');
    }

    public function fileName(): string
    {
        return 'statistiques-'.(new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d').'.pdf';
    }

    // ---- Sections -------------------------------------------------------------------------------------------------

    private function header(\FPDF $pdf, array $d): void
    {
        $pdf->SetFillColor(...self::SAND);
        $pdf->Rect(0, 0, 210, 34, 'F');
        $pdf->SetXY(15, 11);
        $pdf->SetTextColor(...self::INK);
        $pdf->SetFont('Helvetica', 'B', 20);
        $pdf->Cell(0, 8, $this->t('Statistiques de la boutique'), 0, 1);
        $pdf->SetX(15);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(...self::GREY);
        $label = self::PERIOD_LABELS[$d['period']['key']] ?? '';
        $pdf->Cell(0, 6, $this->t("Les galets d’Ambre · $label · du ".$this->date($d['period']['from']).' au '.$this->date($d['period']['to'])), 0, 1);
        $pdf->SetY(42);
    }

    private function kpis(\FPDF $pdf, array $k): void
    {
        $cards = [
            ['Chiffre d’affaires', $this->eur($k['revenue']['value']), $k['revenue']['change'], 'commandes payées'],
            ['Commandes', (string) $k['orders']['value'], $k['orders']['change'], $k['itemsSold']['value'].' article(s) vendu(s)'],
            ['Panier moyen', $this->eur($k['avgBasket']['value']), $k['avgBasket']['change'], 'par commande'],
            ['Clients', (string) $k['customers']['value'], $k['customers']['change'], $k['returning']['share'].' % déjà clients · '.$k['newAccounts']['value'].' nouv. compte(s)'],
        ];
        $w = 43.5;
        $gap = 2;
        $y = $pdf->GetY();
        foreach ($cards as $i => [$label, $value, $change, $sub]) {
            $x = 15 + $i * ($w + $gap);
            $pdf->SetDrawColor(230, 217, 195);
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Rect($x, $y, $w, 26, 'DF');
            $pdf->SetXY($x + 3, $y + 3);
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->SetTextColor(...self::GREY);
            $pdf->Cell($w - 6, 4, $this->t($label), 0, 1);
            $pdf->SetX($x + 3);
            $pdf->SetFont('Helvetica', 'B', 14);
            $pdf->SetTextColor(...self::INK);
            $pdf->Cell($w - 6, 7, $this->t($value), 0, 1);
            $pdf->SetX($x + 3);
            $pdf->SetFont('Helvetica', 'B', 8);
            if ($change !== null) {
                $pdf->SetTextColor(...($change >= 0 ? [22, 120, 60] : [185, 28, 28]));
                $pdf->Cell($w - 6, 4, $this->t(($change >= 0 ? '+' : '-').$this->num(abs($change), 1).' % vs période préc.'), 0, 1);
            } else {
                $pdf->Cell($w - 6, 4, '', 0, 1);
            }
            $pdf->SetX($x + 3);
            $pdf->SetFont('Helvetica', '', 7);
            $pdf->SetTextColor(...self::GREY);
            $pdf->Cell($w - 6, 4, $this->t($this->fit($pdf, $sub, $w - 6)), 0, 1);
        }
        $pdf->SetY($y + 32);
    }

    private function revenue(\FPDF $pdf, array $d): void
    {
        $this->ensure($pdf, 75);
        $this->title($pdf, 'Chiffre d’affaires', $d['series']['unit'] === 'month' ? 'par mois' : 'par jour');
        $points = $d['series']['points'];
        if ($d['kpis']['orders']['value'] === 0) {
            $this->note($pdf, 'Aucune vente sur cette période.');

            return;
        }
        $x = 15;
        $y = $pdf->GetY() + 2;
        $w = 180;
        $h = 42;
        $max = max(array_column($points, 'revenue')) ?: 1;
        $n = count($points);
        $bw = $w / max($n, 1);
        $pdf->SetFillColor(...self::ACCENT);
        foreach ($points as $i => $p) {
            $bh = $p['revenue'] > 0 ? max(0.6, $p['revenue'] / $max * $h) : 0;
            if ($bh > 0) {
                $pdf->Rect($x + $i * $bw + 0.15, $y + $h - $bh, max($bw - 0.3, 0.3), $bh, 'F');
            }
        }
        $pdf->SetDrawColor(200, 190, 170);
        $pdf->Line($x, $y + $h, $x + $w, $y + $h);
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetTextColor(...self::GREY);
        $label = fn (string $date) => $d['series']['unit'] === 'month' ? $this->monthLabel($date) : $this->dayLabel($date);
        $pdf->SetXY($x, $y + $h + 1);
        $pdf->Cell($w / 3, 4, $this->t($label($points[0]['date'])), 0, 0, 'L');
        $pdf->Cell($w / 3, 4, $this->t($label($points[intdiv($n - 1, 2)]['date'])), 0, 0, 'C');
        $pdf->Cell($w / 3, 4, $this->t($label($points[$n - 1]['date'])), 0, 1, 'R');
        $pdf->SetXY($x, $y - 1);
        $pdf->Cell($w, 3, $this->t('Maximum : '.$this->eur($max)), 0, 1, 'R');
        $pdf->SetY($y + $h + 7);
        if ($d['bestDay']) {
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetTextColor(...self::INK);
            $pdf->Cell(0, 5, $this->t('Meilleur jour : '.$this->dayLabel($d['bestDay']['date']).' avec '.$this->eur($d['bestDay']['revenue']).'.'), 0, 1);
        }
        $pdf->Ln(4);
    }

    private function topProducts(\FPDF $pdf, array $top): void
    {
        $this->ensure($pdf, 70);
        $this->title($pdf, 'Meilleurs produits', 'par chiffre d’affaires');
        if (!$top['byRevenue']) {
            $this->note($pdf, 'Aucune vente sur cette période.');

            return;
        }
        $best = $top['best'];
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(...self::INK);
        $pdf->Cell(0, 6, $this->t('Meilleur produit : '.$best['name']).' ('.$this->eur($best['revenue']).', '.$best['qty'].' vendu(s))', 0, 1);
        $max = max(array_column($top['byRevenue'], 'revenue')) ?: 1;
        foreach ($top['byRevenue'] as $i => $p) {
            $y = $pdf->GetY() + 1;
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetTextColor(...self::INK);
            $pdf->SetXY(15, $y);
            $pdf->Cell(7, 5, ($i + 1).'.', 0, 0);
            $pdf->Cell(70, 5, $this->t($this->fit($pdf, $p['name'], 68)), 0, 0);
            $pdf->SetFillColor(...self::SAND);
            $pdf->Rect(94, $y + 1, 60, 3, 'F');
            $pdf->SetFillColor(...self::ACCENT);
            $pdf->Rect(94, $y + 1, max(0.6, $p['revenue'] / $max * 60), 3, 'F');
            $pdf->SetXY(157, $y);
            $pdf->Cell(38, 5, $this->t($this->eur($p['revenue']).' · '.$p['qty'].'×'), 0, 1, 'R');
        }
        $pdf->Ln(4);
    }

    private function categories(\FPDF $pdf, array $cats): void
    {
        $this->ensure($pdf, 45);
        $this->title($pdf, 'Ventes par catégorie');
        $total = array_sum(array_column($cats, 'revenue'));
        if ($total <= 0) {
            $this->note($pdf, 'Aucune vente sur cette période.');

            return;
        }
        $x = 15;
        $y = $pdf->GetY() + 1;
        foreach ($cats as $i => $c) {
            $w = $c['revenue'] / $total * 180;
            $pdf->SetFillColor(...self::PALETTE[$i % count(self::PALETTE)]);
            $pdf->Rect($x, $y, $w, 5, 'F');
            $x += $w;
        }
        $pdf->SetY($y + 8);
        foreach ($cats as $i => $c) {
            $pdf->SetFillColor(...self::PALETTE[$i % count(self::PALETTE)]);
            $yy = $pdf->GetY();
            $pdf->Rect(15, $yy + 1, 3, 3, 'F');
            $pdf->SetXY(20, $yy);
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetTextColor(...self::INK);
            $pdf->Cell(100, 5, $this->t($this->fit($pdf, $c['name'], 95)), 0, 0);
            $pdf->Cell(75, 5, $this->t($this->eur($c['revenue']).' · '.round($c['revenue'] / $total * 100).' %'), 0, 1, 'R');
        }
        $pdf->Ln(4);
    }

    private function weekdaysAndStatuses(\FPDF $pdf, array $d): void
    {
        $this->ensure($pdf, 50);
        $y0 = $pdf->GetY();
        // Gauche : jours d'achat
        $this->title($pdf, 'Jours d’achat', 'commandes par jour');
        $max = max($d['weekdays']) ?: 1;
        $y = $pdf->GetY() + 2;
        $h = 24;
        foreach ($d['weekdays'] as $i => $n) {
            $bh = $n > 0 ? max(0.6, $n / $max * $h) : 0;
            $x = 15 + $i * 12;
            $pdf->SetFillColor(...self::INK);
            if ($bh > 0) {
                $pdf->Rect($x, $y + $h - $bh, 9, $bh, 'F');
            }
            $pdf->SetFont('Helvetica', '', 7);
            $pdf->SetTextColor(...self::GREY);
            $pdf->SetXY($x - 1.5, $y + $h + 1);
            $pdf->Cell(12, 3.5, self::DAYS[$i], 0, 0, 'C');
            if ($n > 0) {
                $pdf->SetXY($x - 1.5, $y + $h - $bh - 4);
                $pdf->Cell(12, 3.5, (string) $n, 0, 0, 'C');
            }
        }
        $leftEnd = $y + $h + 6;
        // Droite : statuts
        $pdf->SetXY(110, $y0);
        $this->title($pdf, 'Commandes par statut', '', 110);
        $pdf->SetFont('Helvetica', '', 9);
        $yy = $pdf->GetY() + 1;
        foreach ($d['statuses'] as $status => $n) {
            if ($n <= 0) {
                continue;
            }
            $pdf->SetXY(110, $yy);
            $pdf->SetTextColor(...self::INK);
            $pdf->Cell(60, 5, $this->t(ucfirst($status)), 0, 0);
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->Cell(25, 5, (string) $n, 0, 0, 'R');
            $pdf->SetFont('Helvetica', '', 9);
            $yy += 5.5;
        }
        $pdf->SetY(max($leftEnd, $yy) + 4);
    }

    private function quotesReviewsStock(\FPDF $pdf, array $d): void
    {
        $q = $d['quotes'];
        $r = $d['reviews'];
        $s = $d['stock'];
        $this->ensure($pdf, 60);
        $this->title($pdf, 'Devis sur mesure');
        $this->lines($pdf, [
            'Demandes reçues : '.$q['received'].' · devis envoyés : '.$q['sent'].' · acomptes payés : '.$q['depositPaid'].($q['conversion'] !== null ? ' · conversion : '.$q['conversion'].' %' : ''),
            $this->eur($q['quotedTotal']).' de devis envoyés, dont '.$this->eur($q['depositTotal']).' d’acomptes encaissés.',
        ]);

        $this->ensure($pdf, 30);
        $this->title($pdf, 'Avis clients', 'toutes périodes');
        if ($r['count'] === 0) {
            $this->note($pdf, 'Aucun avis publié pour le moment.');
        } else {
            $lines = ['Note moyenne : '.$this->num($r['average'], 1).' / 5 sur '.$r['count'].' avis publié(s) · '.$r['pending'].' à modérer.'];
            foreach ($r['best'] as $b) {
                $lines[] = '  '.$this->fit($pdf, $b['name'], 100).' : '.$this->num($b['average'], 1).' / 5 ('.$b['count'].')';
            }
            $this->lines($pdf, $lines);
        }

        $this->ensure($pdf, 40);
        $this->title($pdf, 'Stock', 'en direct, produits publiés');
        $lines = ['Pièces disponibles : '.$s['inStock'].' · vendues : '.$s['soldOut'].'.'];
        $pdf->Ln(0);
        $this->lines($pdf, $lines);
        if ($s['unsold']) {
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetTextColor(...self::INK);
            $pdf->Cell(0, 6, $this->t('Pièces disponibles sans vente sur la période ('.$s['unsoldCount'].')'), 0, 1);
            $lines = [];
            foreach ($s['unsold'] as $p) {
                $lines[] = '  '.$this->fit($pdf, $p['name'], 120).' - en ligne depuis le '.$this->date($p['since']);
            }
            $this->lines($pdf, $lines);
        }
    }

    // ---- Outils ---------------------------------------------------------------------------------------------------

    private function ensure(\FPDF $pdf, float $height): void
    {
        if ($pdf->GetY() + $height > 279) {
            $pdf->AddPage();
        }
    }

    private function title(\FPDF $pdf, string $title, string $hint = '', float $x = 15): void
    {
        $pdf->SetX($x);
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetTextColor(...self::INK);
        $pdf->Cell($hint !== '' ? $pdf->GetStringWidth($this->t($title)) + 3 : 90, 7, $this->t($title), 0, $hint !== '' ? 0 : 1);
        if ($hint !== '') {
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->SetTextColor(...self::GREY);
            $pdf->Cell(0, 7, $this->t($hint), 0, 1);
        }
        $pdf->SetDrawColor(...self::ACCENT);
        $pdf->SetLineWidth(0.4);
        $pdf->Line($x, $pdf->GetY(), $x + 18, $pdf->GetY());
        $pdf->SetLineWidth(0.2);
        $pdf->Ln(2);
    }

    private function note(\FPDF $pdf, string $text): void
    {
        $pdf->SetFont('Helvetica', 'I', 9);
        $pdf->SetTextColor(...self::GREY);
        $pdf->Cell(0, 6, $this->t($text), 0, 1);
        $pdf->Ln(3);
    }

    /** @param string[] $lines */
    private function lines(\FPDF $pdf, array $lines): void
    {
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(...self::INK);
        foreach ($lines as $line) {
            $pdf->Cell(0, 5.2, $this->t($line), 0, 1);
        }
        $pdf->Ln(3);
    }

    /** Raccourcit un texte (avec « … ») pour qu'il tienne dans $width mm, police courante */
    private function fit(\FPDF $pdf, string $text, float $width): string
    {
        if ($pdf->GetStringWidth($this->t($text)) <= $width) {
            return $text;
        }
        while ($text !== '' && $pdf->GetStringWidth($this->t($text.'…')) > $width) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).'…';
    }

    private function num(float|int $n, int $dec = 0): string
    {
        return number_format((float) $n, $dec, ',', ' ');
    }

    private function eur(float|int $n): string
    {
        $dec = abs($n - round($n)) < 0.005 ? 0 : 2;

        return $this->num($n, $dec).' €';
    }

    private function date(string $iso): string
    {
        return \DateTimeImmutable::createFromFormat('Y-m-d', substr($iso, 0, 10))?->format('d/m/Y') ?? $iso;
    }

    private function dayLabel(string $iso): string
    {
        [, $m, $d] = explode('-', $iso);

        return (int) $d.' '.self::MONTHS[(int) $m - 1];
    }

    private function monthLabel(string $iso): string
    {
        [$y, $m] = explode('-', $iso);

        return self::MONTHS[(int) $m - 1].' '.$y;
    }

    /** FPDF (polices standard) attend du Windows-1252 : accents et « € » passent. */
    private function t(string $utf8): string
    {
        return mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');
    }
}
