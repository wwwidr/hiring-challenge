<?php

declare(strict_types=1);

namespace App\Services\ContactFinder;

use App\Services\ContactFinder\Types\MockRecord;
use App\Services\ContactFinder\Types\OutputRow;

final class ContactFinder
{
    public function __construct(
        private readonly Normalizer $normalizer,
        private readonly ContactSelector $contactSelector,
        private readonly Scorer $scorer,
    ) {}

    public function process(string $company_name, string $address, MockRecord $record): OutputRow
    {
        $normalized = $this->normalizer->normalize($company_name, $address);

        $selected = $this->contactSelector->selectBestContact($record);

        $score_result = $this->scorer->score($record, $selected->name);

        $sources = implode(', ', array_filter(
            array_map(fn($url) => $this->extractSourceName($url), $selected->sources)
        ));

        return new OutputRow(
            company_name: $company_name,
            contact_name: $selected->name,
            contact_role: $selected->role,
            contact_email_or_phone: $selected->contact_email_or_phone,
            confidence_score: $score_result->score,
            source: $sources ?: 'unknown',
            needs_human_review: $score_result->needs_human_review,
        );
    }

    private function extractSourceName(string $url): string
    {
        if (str_contains($url, 'secretary-of-state')) {
            return 'secretary_of_state';
        } elseif (str_contains($url, 'google-maps')) {
            return 'google_maps';
        } elseif (str_contains($url, 'linkedin')) {
            return 'linkedin';
        } elseif (str_contains($url, 'company-website')) {
            return 'company_website';
        }

        return 'unknown';
    }
}
