<?php

namespace App\Modules\ContactFinder\Services;

use App\Modules\ContactFinder\ValueObjects\NormalizedCompany;

class CompanyNormalizer
{
    private const SUFFIXES = [
        'llc', 'inc', 'co', 'corp', 'ltd', 'lp', 'llp',
        'incorporated', 'corporation', 'company', 'limited',
    ];

    public function normalize(string $companyName, string $address = ''): NormalizedCompany
    {
        $normalizedName = $this->normalizeName($companyName);
        $fingerprint = $this->generateFingerprint($normalizedName, $address);

        return new NormalizedCompany(
            originalName: $companyName,
            normalizedName: $normalizedName,
            address: $address,
            fingerprint: $fingerprint,
        );
    }

    public function normalizeName(string $name): string
    {
        $normalized = strtolower(trim($name));
        $normalized = preg_replace('/[^\w\s]/', '', $normalized);
        $normalized = $this->stripSuffixes($normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return trim($normalized);
    }

    /**
     * @param NormalizedCompany[] $companies
     * @return NormalizedCompany[]
     */
    public function deduplicate(array $companies): array
    {
        $seen = [];
        $deduplicated = [];

        foreach ($companies as $company) {
            if (isset($seen[$company->fingerprint])) {
                continue;
            }

            $seen[$company->fingerprint] = true;
            $deduplicated[] = $company;
        }

        return $deduplicated;
    }

    private function stripSuffixes(string $name): string
    {
        $words = explode(' ', $name);

        while (count($words) > 1 && in_array(end($words), self::SUFFIXES, true)) {
            array_pop($words);
        }

        return implode(' ', $words);
    }

    private function generateFingerprint(string $normalizedName, string $address): string
    {
        $normalizedAddress = strtolower(trim(preg_replace('/[^\w\s]/', '', $address)));

        return md5($normalizedName . '|' . $normalizedAddress);
    }
}
