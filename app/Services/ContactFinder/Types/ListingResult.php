<?php

declare(strict_types=1);

namespace App\Services\ContactFinder\Types;

final readonly class ListingResult
{
    public function __construct(
        public ?string $name,
        public ?string $phone,
        public string $source_url,
    ) {}
}
