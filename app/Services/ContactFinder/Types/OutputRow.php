<?php

declare(strict_types=1);

namespace App\Services\ContactFinder\Types;

final readonly class OutputRow
{
    public function __construct(
        public string $company_name,
        public ?string $contact_name,
        public ?string $contact_role,
        public ?string $contact_email_or_phone,
        public int $confidence_score,
        public string $source,
        public bool $needs_human_review,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'company_name' => $this->company_name,
            'contact_name' => $this->contact_name,
            'contact_role' => $this->contact_role,
            'contact_email_or_phone' => $this->contact_email_or_phone,
            'confidence_score' => $this->confidence_score,
            'source' => $this->source,
            'needs_human_review' => $this->needs_human_review ? 'true' : 'false',
        ];
    }
}
