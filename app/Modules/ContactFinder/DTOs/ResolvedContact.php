<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\DTOs;

/**
 * The final per-row output of the contact finder.
 *
 * Maps directly to the columns required by challenge/PROBLEM.md, plus
 * source_urls (provenance, per mocks/README.md) and a machine-readable reason.
 */
final readonly class ResolvedContact
{
    /**
     * @param list<string> $sources     contributing provider keys, e.g. ["registry","listing"]
     * @param list<string> $sourceUrls  mock:// provenance, one per contributing source
     */
    public function __construct(
        public string $companyName,
        public string $mailingAddress,
        public string $contactName,
        public string $contactRole,
        public string $contactEmailOrPhone,
        public int $confidenceScore,
        public array $sources,
        public array $sourceUrls,
        public bool $needsHumanReview,
        public string $reason,
        public ScoreBreakdown $breakdown,
    ) {
    }

    /** @return array<string,string> Row shaped for CSV / table output. */
    public function toRow(): array
    {
        return [
            'company_name' => $this->companyName,
            'mailing_address' => $this->mailingAddress,
            'contact_name' => $this->contactName,
            'contact_role' => $this->contactRole,
            'contact_email_or_phone' => $this->contactEmailOrPhone,
            'confidence_score' => (string) $this->confidenceScore,
            'source' => implode('|', $this->sources),
            'needs_human_review' => $this->needsHumanReview ? 'true' : 'false',
            'source_urls' => implode('|', $this->sourceUrls),
            'reason' => $this->reason,
        ];
    }

    /** @return list<string> Column order for the output CSV header. */
    public static function columns(): array
    {
        return [
            'company_name',
            'mailing_address',
            'contact_name',
            'contact_role',
            'contact_email_or_phone',
            'confidence_score',
            'source',
            'needs_human_review',
            'source_urls',
            'reason',
        ];
    }
}
