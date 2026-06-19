<?php

namespace App\Modules\ContactFinder\ValueObjects;

use App\Modules\ContactFinder\Enums\VerificationStatus;

class ScoredContact
{
    /**
     * @param array{agreement: float, authority: float, completeness: float, recency: float} $componentScores
     * @param array<string, string> $allRoles
     */
    public function __construct(
        public readonly string $companyName,
        public readonly string $contactName,
        public readonly string $contactRole,
        public readonly string $contactEmail,
        public readonly string $contactPhone,
        public readonly int $confidenceScore,
        public readonly VerificationStatus $verificationStatus,
        public readonly bool $needsHumanReview,
        public readonly string $source,
        public readonly array $provenance,
        public readonly string $explanation = '',
        public readonly array $componentScores = [],
        public readonly array $allRoles = [],
        public readonly ?string $regulatedIndustry = null,
    ) {}

    public function toArray(): array
    {
        $output = [
            'company_name' => $this->companyName,
            'contact_name' => $this->contactName,
            'contact_role' => $this->contactRole,
            'contact_email' => $this->contactEmail,
            'contact_phone' => $this->contactPhone,
            'confidence_score' => $this->confidenceScore,
            'source' => $this->source,
            'needs_human_review' => $this->needsHumanReview,
            'verification_status' => $this->verificationStatus->value,
            'explanation' => $this->explanation,
            'provenance' => $this->provenance,
        ];

        if (count($this->allRoles) > 1) {
            $output['all_roles'] = $this->allRoles;
        }

        if ($this->regulatedIndustry !== null) {
            $output['regulated_industry'] = $this->regulatedIndustry;
        }

        return $output;
    }
}
