<?php

declare(strict_types=1);

namespace App\Services\ContactFinder\Types;

final readonly class NormalizedCompany
{
    public function __construct(
        public string $legal_name,
        public string $common_name,
        public ?string $entity_type,
        public string $street,
        public string $city,
        public string $state,
        public string $zip,
    ) {}
}
