<?php

namespace App\Entity;

// Un avis n'est visible sur le site qu'une fois « publié » par l'administrateur
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
