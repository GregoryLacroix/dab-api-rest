<?php

namespace App\Dto;

enum VerificationResult: string
{
    case Ok = 'ok';
    case Invalid = 'invalid';
    case Expired = 'expired';
}
