<?php

declare(strict_types=1);

namespace App\Services\ContactFinder\Types;

final readonly class RegistryResult
{
    public function __construct(
        public ?string $name,
        public string $role,
        public string $source_url,
    ) {}
}
