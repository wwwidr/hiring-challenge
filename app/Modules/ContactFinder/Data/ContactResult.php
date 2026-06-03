<?php

namespace App\Modules\ContactFinder\Data;

/**
 * One output row: the six fields required by the challenge plus provenance
 * (source_urls) and an explainable rationale.
 */
class ContactResult
{
    /**
     * @param  string[]  $sources
     * @param  string[]  $sourceUrls
     */
    public function __construct(
        public readonly string $companyName,
        public readonly string $contactName,
        public readonly string $contactRole,
        public readonly string $contactEmailOrPhone,
        public readonly int $confidenceScore,
        public readonly array $sources,
        public readonly array $sourceUrls,
        public readonly bool $needsHumanReview,
        public readonly string $rationale,
    ) {}

    /**
     * Shape used for the JSON output (rich: carries provenance + rationale).
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'company_name' => $this->companyName,
            'contact_name' => $this->contactName,
            'contact_role' => $this->contactRole,
            'contact_email_or_phone' => $this->contactEmailOrPhone,
            'confidence_score' => $this->confidenceScore,
            'source' => implode('+', $this->sources),
            'needs_human_review' => $this->needsHumanReview,
            'source_urls' => $this->sourceUrls,
            'rationale' => $this->rationale,
        ];
    }

    /**
     * Flat shape for the CSV output (the contracted columns, provenance folded
     * into single cells).
     *
     * @return array<string,string>
     */
    public function toCsvRow(): array
    {
        return [
            'company_name' => $this->companyName,
            'contact_name' => $this->contactName,
            'contact_role' => $this->contactRole,
            'contact_email_or_phone' => $this->contactEmailOrPhone,
            'confidence_score' => (string) $this->confidenceScore,
            'source' => implode('+', $this->sources),
            'needs_human_review' => $this->needsHumanReview ? 'true' : 'false',
            'source_urls' => implode(' | ', $this->sourceUrls),
            'rationale' => $this->rationale,
        ];
    }
}
