<?php

namespace App\Modules\ContactFinder\Enums;

enum VerificationStatus: string
{
    case VERIFIED = 'verified';
    case UNVERIFIED = 'unverified';
    case CONFLICTING = 'conflicting';
    case NOT_FOUND = 'not_found';
}
