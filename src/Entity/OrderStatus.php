<?php

namespace App\Entity;

// Mêmes valeurs que ORDER_STATUSES côté React
enum OrderStatus: string
{
    case Pending = 'en attente';
    case Paid = 'payée';
    case Shipped = 'expédiée';
    case Delivered = 'livrée';
    case Cancelled = 'annulée';
}
