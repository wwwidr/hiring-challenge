<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\DTOs;

/**
 * One provider's normalized answer for a single company.
 *
 * Every field is nullable: a provider may know a name but no email, a phone but
 * no name, or return almost nothing. Absence is a first-class signal here.
 */
final readonly class ProviderResult
{
    public function __construct(
        public string $provider,
        public ?string $name = null,
        public ?string $role = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?int $providerConfidence = null,
        public ?string $sourceUrl = null,
    ) {
    }

    public function hasName(): bool
    {
        return $this->name !== null && trim($this->name) !== '';
    }

    public function hasEmail(): bool
    {
        return $this->email !== null && trim($this->email) !== '';
    }

    public function hasPhone(): bool
    {
        return $this->phone !== null && trim($this->phone) !== '';
    }
}
