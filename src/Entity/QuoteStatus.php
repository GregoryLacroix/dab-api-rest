<?php

namespace App\Entity;

// Mêmes valeurs que QUOTE_STATUSES côté React (back-office)
enum QuoteStatus: string
{
    case New = 'nouvelle';
    case Quoted = 'devis envoyé';
    case DepositPaid = 'acompte payé';
    case Declined = 'refusée';
    case Archived = 'archivée';
}
