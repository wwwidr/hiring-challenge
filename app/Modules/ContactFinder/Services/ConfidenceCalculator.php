<?php

namespace App\Modules\ContactFinder\Services;

use App\Modules\ContactFinder\ValueObjects\ProviderResult;

class ConfidenceCalculator
{
    private readonly float $agreementWeight;
    private readonly float $authorityWeight;
    private readonly float $completenessWeight;
    private readonly float $recencyWeight;

    /** @var string[] */
    private readonly array $genericEmailPrefixes;

    public function __construct()
    {
        $weights = config('enrichment.weights');
        $this->agreementWeight = $weights['agreement'];
        $this->authorityWeight = $weights['authority'];
        $this->completenessWeight = $weights['completeness'];
        $this->recencyWeight = $weights['recency'];
        $this->genericEmailPrefixes = config('enrichment.generic_email_prefixes', []);
    }

    /**
     * @param ProviderResult[] $results
     * @param array{name: string, role: string, email: string, phone: string} $mergedContact
     */
    public function calculate(array $results, array $mergedContact): int
    {
        $components = $this->calculateComponents($results, $mergedContact);

        return $components['final_score'];
    }

    /**
     * Returns all component scores for transparency and weight calibration.
     *
     * @param ProviderResult[] $results
     * @param array{name: string, role: string, email: string, phone: string} $mergedContact
     * @return array{agreement: float, authority: float, completeness: float, recency: float, final_score: int}
     */
    public function calculateComponents(array $results, array $mergedContact): array
    {
        if (empty($results)) {
            return [
                'agreement' => 0.0,
                'authority' => 0.0,
                'completeness' => 0.0,
                'recency' => 0.0,
                'final_score' => 0,
            ];
        }

        $agreementScore = $this->calculateAgreement($results);
        $authorityScore = $this->calculateAuthority($results);
        $completenessScore = $this->calculateCompleteness($mergedContact);
        $recencyScore = $this->calculateRecency();

        $confidence = (
            $this->agreementWeight * $agreementScore
            + $this->authorityWeight * $authorityScore
            + $this->completenessWeight * $completenessScore
            + $this->recencyWeight * $recencyScore
        ) * 100;

        return [
            'agreement' => $agreementScore,
            'authority' => $authorityScore,
            'completeness' => $completenessScore,
            'recency' => $recencyScore,
            'final_score' => (int) round(max(0, min(100, $confidence))),
        ];
    }

    /**
     * @param ProviderResult[] $results
     */
    public function calculateAgreement(array $results): float
    {
        $sourcesWithNames = array_filter($results, fn (ProviderResult $result) => $result->hasName());
        $count = count($sourcesWithNames);

        if ($count === 0) {
            return 0.0;
        }

        if ($count === 1) {
            return 0.5;
        }

        $names = array_map(
            fn (ProviderResult $result) => $this->normalizeNameForComparison($result->name),
            $sourcesWithNames,
        );

        if ($this->namesAgree(array_values($names))) {
            return 1.0;
        }

        return 0.0;
    }

    /**
     * Check if all names agree, considering initials (e.g. "S. Murphy" matches "Sean Murphy").
     *
     * @param string[] $normalizedNames
     */
    private function namesAgree(array $normalizedNames): bool
    {
        $uniqueNames = array_unique($normalizedNames);

        if (count($uniqueNames) === 1) {
            return true;
        }

        for ($i = 0; $i < count($normalizedNames); $i++) {
            for ($j = $i + 1; $j < count($normalizedNames); $j++) {
                if (!$this->twoNamesMatch($normalizedNames[$i], $normalizedNames[$j])) {
                    return false;
                }
            }
        }

        return true;
    }

    private function twoNamesMatch(string $nameA, string $nameB): bool
    {
        if ($nameA === $nameB) {
            return true;
        }

        $partsA = explode(' ', $nameA);
        $partsB = explode(' ', $nameB);

        if (count($partsA) < 2 || count($partsB) < 2) {
            return false;
        }

        $lastA = end($partsA);
        $lastB = end($partsB);

        if ($lastA !== $lastB) {
            return false;
        }

        $firstA = $partsA[0];
        $firstB = $partsB[0];

        if (strlen($firstA) === 1 && str_starts_with($firstB, $firstA)) {
            return true;
        }

        if (strlen($firstB) === 1 && str_starts_with($firstA, $firstB)) {
            return true;
        }

        return false;
    }

    /**
     * @param ProviderResult[] $results
     */
    public function calculateAuthority(array $results): float
    {
        if (empty($results)) {
            return 0.0;
        }

        return max(array_map(
            fn (ProviderResult $result) => $result->authorityWeight,
            $results,
        ));
    }

    /**
     * @param array{name: string, role: string, email: string, phone: string} $mergedContact
     */
    public function calculateCompleteness(array $mergedContact): float
    {
        $requiredFields = 3;
        $presentFields = 0;

        if (!empty($mergedContact['name'])) {
            $presentFields++;
        }

        if (!empty($mergedContact['role'])) {
            $presentFields++;
        }

        $hasNonGenericEmail = !empty($mergedContact['email'])
            && !$this->isGenericEmail($mergedContact['email']);
        $hasPhone = !empty($mergedContact['phone']);

        if ($hasNonGenericEmail || $hasPhone) {
            $presentFields++;
        }

        return $presentFields / $requiredFields;
    }

    /**
     * Mock providers have no timestamp data — default to 0.5 (unknown age).
     */
    public function calculateRecency(): float
    {
        return 0.5;
    }

    public function isGenericEmail(string $email): bool
    {
        $localPart = strtolower(explode('@', $email)[0] ?? '');

        return in_array($localPart, $this->genericEmailPrefixes, true);
    }

    /**
     * Normalize a name for comparison: handles nicknames, initials, titles.
     */
    public function normalizeNameForComparison(?string $name): string
    {
        if ($name === null || $name === '') {
            return '';
        }

        $name = strtolower(trim($name));
        $name = preg_replace('/\(.*?\)/', '', $name);
        $name = preg_replace('/^(dr\.?|mr\.?|mrs\.?|ms\.?)\s+/i', '', $name);
        $name = trim($name);

        $parts = preg_split('/\s+/', $name);
        if (count($parts) < 2) {
            return $name;
        }

        $lastName = end($parts);
        $firstPart = $parts[0];

        $firstPart = $this->expandNickname($firstPart);

        if (strlen($firstPart) <= 2 && str_ends_with($firstPart, '.')) {
            $firstPart = rtrim($firstPart, '.');
        }

        return $firstPart . ' ' . $lastName;
    }

    private function expandNickname(string $name): string
    {
        $nicknames = [
            'bob' => 'robert',
            'rob' => 'robert',
            'bobby' => 'robert',
            'bill' => 'william',
            'will' => 'william',
            'willy' => 'william',
            'jim' => 'james',
            'jimmy' => 'james',
            'mike' => 'michael',
            'dan' => 'daniel',
            'danny' => 'daniel',
            'dave' => 'david',
            'tom' => 'thomas',
            'tommy' => 'thomas',
            'dick' => 'richard',
            'rick' => 'richard',
            'rich' => 'richard',
            'joe' => 'joseph',
            'joey' => 'joseph',
            'tony' => 'anthony',
            'ted' => 'theodore',
            'ed' => 'edward',
            'eddie' => 'edward',
            'al' => 'albert',
            'alex' => 'alexander',
            'chris' => 'christopher',
            'pat' => 'patrick',
            'matt' => 'matthew',
            'steve' => 'steven',
            'andy' => 'andrew',
            'drew' => 'andrew',
            'charlie' => 'charles',
            'chuck' => 'charles',
            'harry' => 'harold',
            'larry' => 'lawrence',
            'jerry' => 'gerald',
            'sam' => 'samuel',
            'ben' => 'benjamin',
            'nick' => 'nicholas',
            'frank' => 'franklin',
            'fred' => 'frederick',
            'jack' => 'john',
            'jake' => 'jacob',
            'liz' => 'elizabeth',
            'beth' => 'elizabeth',
            'kate' => 'katherine',
            'kathy' => 'katherine',
            'jenny' => 'jennifer',
            'jen' => 'jennifer',
            'pam' => 'pamela',
            'sue' => 'susan',
            'meg' => 'margaret',
            'peggy' => 'margaret',
        ];

        return $nicknames[$name] ?? $name;
    }
}
