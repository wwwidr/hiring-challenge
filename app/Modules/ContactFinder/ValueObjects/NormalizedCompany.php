<?php

namespace App\Modules\ContactFinder\ValueObjects;

class NormalizedCompany
{
    public function __construct(
        public readonly string $originalName,
        public readonly string $normalizedName,
        public readonly string $address,
        public readonly string $fingerprint,
    ) {}
}
