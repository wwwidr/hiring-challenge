<?php

namespace App\Modules\ContactFinder\Services;

class RegulatedIndustryDetector
{
    private const HEALTHCARE_KEYWORDS = [
        'dental', 'medical', 'clinic', 'veterinary', 'vet', 'hospital',
        'health', 'pharma', 'pharmacy', 'surgical', 'orthopedic',
        'chiropractic', 'optometry', 'dermatology', 'pediatric',
        'nursing', 'therapy', 'rehabilitation', 'mental health',
    ];

    private const FINANCE_KEYWORDS = [
        'financial', 'insurance', 'accounting', 'cpa', 'tax',
        'investment', 'bank', 'credit', 'mortgage', 'securities',
        'wealth', 'advisory', 'fiduciary', 'brokerage', 'lending',
    ];

    public function detect(string $companyName): ?string
    {
        $normalized = strtolower($companyName);

        foreach (self::HEALTHCARE_KEYWORDS as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return 'healthcare';
            }
        }

        foreach (self::FINANCE_KEYWORDS as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return 'finance';
            }
        }

        return null;
    }

    public function isRegulated(string $companyName): bool
    {
        return $this->detect($companyName) !== null;
    }
}
