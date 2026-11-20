<?php

namespace App\Service;

use App\Entity\Money;
use App\Entity\Order;
use App\Entity\OrderStatus;
use App\Entity\Product;
use App\Entity\QuoteRequest;
use App\Entity\QuoteStatus;
use App\Entity\Review;
use App\Entity\ReviewStatus;
use App\Entity\User;
use App\Repository\ProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

// Statistiques du « Tableau de bord » : tout est calculé à la demande à partir des commandes, devis, avis et produits.
// Une « vente » = commande non annulée, hors paiement en ligne encore en attente (comme le chiffre d'affaires du back-office).
final class AnalyticsService
{
    public const PERIODS = ['7' => 7, '30' => 30, '90' => 90, '365' => 365, 'all' => null];
    private const TZ = 'Europe/Paris';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductRepository $products,
    ) {
    }

    public function compute(string $period): array
    {
        $days = self::PERIODS[$period] ?? self::PERIODS['30'];
        $period = array_key_exists($period, self::PERIODS) ? $period : '30';
        $tz = new \DateTimeZone(self::TZ);
        $now = new \DateTimeImmutable('now', $tz);

        $allOrders = $this->em->createQueryBuilder()->select('o')->from(Order::class, 'o')->orderBy('o.id', 'ASC')->getQuery()->getResult();
        /** @var Order[] $sales */
        $sales = array_values(array_filter($allOrders, [$this, 'isSale']));

        // Bornes de la période et de la période précédente (de même durée) pour les évolutions en %
        if ($days === null) {
            $first = $sales === [] ? $now : $this->when($sales[0], $tz);
            $from = $first->setTime(0, 0);
            $prevFrom = $prevTo = null;
            $days = max(1, (int) $from->diff($now)->days + 1);
        } else {
            $from = $now->setTime(0, 0)->modify('-'.($days - 1).' days');
            $prevTo = $from;
            $prevFrom = $from->modify("-$days days");
        }
        $to = $now->modify('+1 second');

        $current = $this->between($sales, $from, $to, $tz);
        $previous = $prevFrom === null ? null : $this->between($sales, $prevFrom, $prevTo, $tz);

        return [
            'period' => ['key' => $period, 'days' => $days, 'from' => $from->format('Y-m-d'), 'to' => $now->format('Y-m-d')],
            'kpis' => $this->kpis($current, $previous, $sales, $from, $to, $tz),
            'series' => $this->series($current, $from, $now, $days, $tz),
            'bestDay' => $this->bestDay($current, $tz),
            'topProducts' => $this->topProducts($current),
            'categories' => $this->categories($current),
            'weekdays' => $this->weekdays($current, $tz),
            'statuses' => $this->statuses($allOrders, $from, $to, $tz),
            'quotes' => $this->quotes($from, $to, $tz),
            'reviews' => $this->reviews(),
            'stock' => $this->stock($current),
        ];
    }

    private function isSale(Order $o): bool
    {
        return $o->getStatus() !== OrderStatus::Cancelled
            && !($o->getStatus() === OrderStatus::Pending && $o->getStripeSessionId() !== null);
    }

    private function when(Order $o, \DateTimeZone $tz): \DateTimeImmutable
    {
        return ($o->getPaidAt() ?? $o->getCreatedAt())->setTimezone($tz);
    }

    /** @param Order[] $sales @return Order[] */
    private function between(array $sales, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $tz): array
    {
        return array_values(array_filter($sales, function (Order $o) use ($from, $to, $tz) {
            $t = $this->when($o, $tz);

            return $t >= $from && $t < $to;
        }));
    }

    private static function euros(int $cents): int|float
    {
        return Money::fromCents($cents);
    }

    /** @param Order[] $orders */
    private function totals(array $orders): array
    {
        $cents = 0;
        $items = 0;
        $emails = [];
        foreach ($orders as $o) {
            $cents += $o->getTotalCents();
            foreach ($o->getItems() as $it) {
                $items += (int) ($it['qty'] ?? 0);
            }
            $emails[mb_strtolower($o->getCustomerEmail())] = true;
        }
        $n = count($orders);

        return [
            'revenue' => $cents / 100,
            'orders' => $n,
            'avgBasket' => $n > 0 ? round($cents / 100 / $n, 2) : 0,
            'itemsSold' => $items,
            'customers' => count($emails),
        ];
    }

    /** @param Order[] $current @param Order[]|null $previous @param Order[] $sales */
    private function kpis(array $current, ?array $previous, array $sales, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $tz): array
    {
        $cur = $this->totals($current);
        $prev = $previous === null ? null : $this->totals($previous);
        $out = [];
        foreach ($cur as $key => $value) {
            $p = $prev[$key] ?? null;
            $out[$key] = [
                'value' => $value,
                'previous' => $p,
                'change' => ($p !== null && $p > 0) ? round(($value - $p) / $p * 100, 1) : null, // null = pas de comparaison possible
            ];
        }

        // Clients qui ont déjà commandé avant la période : part de clients fidèles
        $before = [];
        foreach ($sales as $o) {
            if ($this->when($o, $tz) < $from) {
                $before[mb_strtolower($o->getCustomerEmail())] = true;
            }
        }
        $curEmails = [];
        foreach ($current as $o) {
            $curEmails[mb_strtolower($o->getCustomerEmail())] = true;
        }
        $returning = count(array_intersect_key($curEmails, $before));
        $out['returning'] = ['value' => $returning, 'share' => count($curEmails) > 0 ? round($returning / count($curEmails) * 100) : 0];

        $newAccounts = (int) $this->em->createQueryBuilder()->select('COUNT(u.id)')->from(User::class, 'u')
            ->where('u.createdAt >= :f')->andWhere('u.createdAt < :t')
            ->setParameter('f', $from->setTimezone(new \DateTimeZone('UTC')), Types::DATETIME_IMMUTABLE)->setParameter('t', $to->setTimezone(new \DateTimeZone('UTC')), Types::DATETIME_IMMUTABLE)
            ->getQuery()->getSingleScalarResult();
        $out['newAccounts'] = ['value' => $newAccounts];

        return $out;
    }

    /** Une valeur par jour (périodes jusqu'à 90 jours) ou par mois (au-delà) */
    private function series(array $orders, \DateTimeImmutable $from, \DateTimeImmutable $now, int $days, \DateTimeZone $tz): array
    {
        $monthly = $days > 90;
        $fmt = $monthly ? 'Y-m' : 'Y-m-d';
        $buckets = [];
        $cursor = $monthly ? $from->modify('first day of this month') : $from;
        while ($cursor <= $now) {
            $buckets[$cursor->format($fmt)] = ['date' => $cursor->format($fmt), 'revenue' => 0, 'orders' => 0];
            $cursor = $monthly ? $cursor->modify('+1 month') : $cursor->modify('+1 day');
        }
        foreach ($orders as $o) {
            $k = $this->when($o, $tz)->format($fmt);
            if (isset($buckets[$k])) {
                $buckets[$k]['revenue'] += $o->getTotalCents() / 100;
                ++$buckets[$k]['orders'];
            }
        }

        return ['unit' => $monthly ? 'month' : 'day', 'points' => array_values($buckets)];
    }

    private function bestDay(array $orders, \DateTimeZone $tz): ?array
    {
        $byDay = [];
        foreach ($orders as $o) {
            $k = $this->when($o, $tz)->format('Y-m-d');
            $byDay[$k] = ($byDay[$k] ?? 0) + $o->getTotalCents();
        }
        if ($byDay === []) {
            return null;
        }
        arsort($byDay);
        $date = array_key_first($byDay);

        return ['date' => $date, 'revenue' => self::euros($byDay[$date])];
    }

    /** @param Order[] $orders @return array<int, array{name: string, qty: int, revenue: float}> par identifiant de produit */
    private function perProduct(array $orders): array
    {
        $map = [];
        foreach ($orders as $o) {
            foreach ($o->getItems() as $it) {
                $id = (int) ($it['productId'] ?? 0);
                $qty = (int) ($it['qty'] ?? 0);
                $map[$id] ??= ['name' => (string) ($it['name'] ?? '?'), 'qty' => 0, 'revenue' => 0.0];
                $map[$id]['qty'] += $qty;
                $map[$id]['revenue'] += $qty * (float) ($it['price'] ?? 0);
            }
        }

        return $map;
    }

    private function topProducts(array $orders): array
    {
        $map = $this->perProduct($orders);
        $rows = [];
        foreach ($map as $id => $r) {
            $rows[] = ['id' => $id, 'name' => $r['name'], 'qty' => $r['qty'], 'revenue' => round($r['revenue'], 2)];
        }
        $byRevenue = $rows;
        usort($byRevenue, static fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
        $byQty = $rows;
        usort($byQty, static fn ($a, $b) => [$b['qty'], $b['revenue']] <=> [$a['qty'], $a['revenue']]);

        return ['byRevenue' => array_slice($byRevenue, 0, 5), 'byQty' => array_slice($byQty, 0, 5), 'best' => $byRevenue[0] ?? null];
    }

    /** Chiffre d'affaires par catégorie (les produits supprimés vont dans « Autres ») */
    private function categories(array $orders): array
    {
        $map = $this->perProduct($orders);
        $known = $this->products->findBy(['id' => array_keys($map) ?: [0]]);
        $catOf = [];
        foreach ($known as $p) {
            /** @var Product $p */
            $catOf[$p->getId()] = $p->getCategory()->getName();
        }
        $totals = [];
        foreach ($map as $id => $r) {
            $name = $catOf[$id] ?? 'Autres';
            $totals[$name] = ($totals[$name] ?? 0) + $r['revenue'];
        }
        arsort($totals);

        return array_map(static fn ($name, $rev) => ['name' => $name, 'revenue' => round($rev, 2)], array_keys($totals), array_values($totals));
    }

    /** Commandes par jour de la semaine (lundi = 0) */
    private function weekdays(array $orders, \DateTimeZone $tz): array
    {
        $out = array_fill(0, 7, 0);
        foreach ($orders as $o) {
            ++$out[(int) $this->when($o, $tz)->format('N') - 1];
        }

        return $out;
    }

    /** @param Order[] $all */
    private function statuses(array $all, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $tz): array
    {
        $out = [];
        foreach (OrderStatus::cases() as $s) {
            $out[$s->value] = 0;
        }
        foreach ($all as $o) {
            // Paiements en ligne abandonnés : ce ne sont pas de vraies commandes
            if ($o->getStatus() === OrderStatus::Cancelled && $o->getPaidAt() === null && $o->getStripeSessionId() !== null) {
                continue;
            }
            $t = $o->getCreatedAt()->setTimezone($tz);
            if ($t >= $from && $t < $to) {
                ++$out[$o->getStatus()->value];
            }
        }

        return $out;
    }

    private function quotes(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $tz): array
    {
        /** @var QuoteRequest[] $list */
        $list = $this->em->createQueryBuilder()->select('q')->from(QuoteRequest::class, 'q')
            ->where('q.createdAt >= :f')->andWhere('q.createdAt < :t')
            ->setParameter('f', $from->setTimezone(new \DateTimeZone('UTC')), Types::DATETIME_IMMUTABLE)->setParameter('t', $to->setTimezone(new \DateTimeZone('UTC')), Types::DATETIME_IMMUTABLE)
            ->getQuery()->getResult();
        $sent = 0;
        $paid = 0;
        $deposit = 0;
        $quoted = 0;
        foreach ($list as $q) {
            if (\in_array($q->getStatus(), [QuoteStatus::Quoted, QuoteStatus::DepositPaid], true)) {
                ++$sent;
                $quoted += $q->getAmountCents() ?? 0;
            }
            if ($q->getDepositPaidAt() !== null) {
                ++$paid;
                $deposit += $q->depositCents() ?? 0;
            }
        }

        return [
            'received' => count($list),
            'sent' => $sent,
            'depositPaid' => $paid,
            'depositTotal' => self::euros($deposit),
            'quotedTotal' => self::euros($quoted),
            'conversion' => $sent > 0 ? round($paid / $sent * 100) : null,
        ];
    }

    /** Avis : toutes périodes confondues (une note n'est pas liée à la période du tableau de bord) */
    private function reviews(): array
    {
        $rows = $this->em->createQueryBuilder()->select('IDENTITY(r.product) AS pid, AVG(r.rating) AS avg, COUNT(r.id) AS n')
            ->from(Review::class, 'r')->where('r.status = :s')->setParameter('s', ReviewStatus::Approved->value)
            ->groupBy('r.product')->getQuery()->getArrayResult();
        $count = 0;
        $sum = 0;
        foreach ($rows as $r) {
            $count += (int) $r['n'];
            $sum += (float) $r['avg'] * (int) $r['n'];
        }
        usort($rows, static fn ($a, $b) => [(float) $b['avg'], (int) $b['n']] <=> [(float) $a['avg'], (int) $a['n']]);
        $best = [];
        foreach (array_slice($rows, 0, 3) as $r) {
            $p = $this->products->find((int) $r['pid']);
            if ($p !== null) {
                $best[] = ['id' => $p->getId(), 'name' => $p->getName(), 'average' => round((float) $r['avg'], 1), 'count' => (int) $r['n']];
            }
        }
        $pending = (int) $this->em->createQueryBuilder()->select('COUNT(r.id)')->from(Review::class, 'r')
            ->where('r.status = :s')->setParameter('s', ReviewStatus::Pending->value)->getQuery()->getSingleScalarResult();

        return ['count' => $count, 'average' => $count > 0 ? round($sum / $count, 1) : null, 'pending' => $pending, 'best' => $best];
    }

    /** État du stock (en direct) et pièces publiées qui ne se sont pas vendues sur la période */
    private function stock(array $orders): array
    {
        $sold = $this->perProduct($orders);
        $published = $this->products->findPublished();
        $inStock = 0;
        $soldOut = 0;
        $unsold = [];
        foreach ($published as $p) {
            /** @var Product $p */
            if ($p->getStock() > 0) {
                ++$inStock;
                if (!isset($sold[$p->getId()])) {
                    $unsold[] = ['id' => $p->getId(), 'name' => $p->getName(), 'since' => $p->getCreatedAt()->format('Y-m-d')];
                }
            } else {
                ++$soldOut;
            }
        }
        usort($unsold, static fn ($a, $b) => $a['since'] <=> $b['since']); // les plus anciennes d'abord

        return ['inStock' => $inStock, 'soldOut' => $soldOut, 'unsoldCount' => count($unsold), 'unsold' => array_slice($unsold, 0, 5)];
    }
}
