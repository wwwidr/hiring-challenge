<?php

namespace App\Modules\ContactFinder\ValueObjects;

class ProviderResult
{
    public function __construct(
        public readonly string $providerName,
        public readonly float $authorityWeight,
        public readonly ?string $name = null,
        public readonly ?string $role = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?int $providerConfidence = null,
        public readonly ?string $sourceUrl = null,
    ) {}

    public function hasName(): bool
    {
        return $this->name !== null && $this->name !== '';
    }

    public function hasContactChannel(): bool
    {
        return $this->hasEmail() || $this->hasPhone();
    }

    public function hasEmail(): bool
    {
        return $this->email !== null && $this->email !== '';
    }

    public function hasPhone(): bool
    {
        return $this->phone !== null && $this->phone !== '';
    }
}
