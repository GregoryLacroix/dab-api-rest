<?php

namespace App\Entity;

// Les montants sont stockés en centimes (entiers) et exposés en euros, comme dans le React (28, 24.5…)
final class Money
{
    public static function fromCents(int $cents): int|float
    {
        return $cents % 100 === 0 ? intdiv($cents, 100) : $cents / 100;
    }

    public static function toCents(float|int|string $euros): int
    {
        return (int) round(((float) $euros) * 100);
    }
}
