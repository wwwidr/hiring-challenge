<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder;

use RuntimeException;

class ContactFinder
{
    public const CONFIDENCE_THRESHOLD = 70;

    private const PROVIDERS = ['registry', 'listing', 'enrichment'];

    private const GENERIC_EMAIL_LOCALS = [
        'admin',
        'ap',
        'billing',
        'contact',
        'finance',
        'hello',
        'info',
        'office',
        'sales',
        'service',
        'support',
    ];

    private const NICKNAMES = [
        'bob' => 'robert',
        'bobby' => 'robert',
        'dan' => 'daniel',
        'dave' => 'david',
        'jeff' => 'jeffrey',
        'rob' => 'robert',
        'tom' => 'thomas',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function run(string $companiesCsvPath, string $mockResponsesPath, string $outputCsvPath): array
    {
        $results = $this->resolveRows(
            $this->loadCompanies($companiesCsvPath),
            $this->loadProviderResponses($mockResponsesPath),
        );

        $this->writeCsv($outputCsvPath, $results);

        return $results;
    }

    /**
     * @return array<int, array{company_name: string, mailing_address: string}>
     */
    public function loadCompanies(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Company CSV not found: {$path}");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to read company CSV: {$path}");
        }

        $headers = fgetcsv($handle, null, ',', '"', '');

        if ($headers === false) {
            fclose($handle);

            throw new RuntimeException("Company CSV is empty: {$path}");
        }

        $rows = [];

        while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($values === [null] || $values === []) {
                continue;
            }

            $row = [];

            foreach ($headers as $index => $header) {
                $row[(string) $header] = trim((string) ($values[$index] ?? ''));
            }

            if (($row['company_name'] ?? '') === '') {
                continue;
            }

            $rows[] = [
                'company_name' => $row['company_name'],
                'mailing_address' => $row['mailing_address'] ?? '',
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function loadProviderResponses(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Mock response file not found: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("Mock response file must contain a JSON object: {$path}");
        }

        return $decoded;
    }

    /**
     * @param  array<int, array<string, string>>  $companies
     * @param  array<string, array<string, array<string, mixed>>>  $responses
     * @return array<int, array<string, mixed>>
     */
    public function resolveRows(array $companies, array $responses): array
    {
        $results = [];

        foreach ($companies as $company) {
            $companyName = (string) ($company['company_name'] ?? '');
            $results[] = $this->resolveCompany($company, $responses[$companyName] ?? []);
        }

        return $results;
    }

    /**
     * @param  array<string, string>  $company
     * @param  array<string, array<string, mixed>>  $providerData
     * @return array<string, mixed>
     */
    public function resolveCompany(array $company, array $providerData): array
    {
        $sourceEntries = $this->sourceEntries($providerData);

        if ($sourceEntries === []) {
            return $this->outputRow($company, '', '', '', 0, '', true);
        }

        $name = $this->bestName($providerData);
        $role = $this->bestRole($providerData);
        $email = $this->value($providerData['enrichment']['email'] ?? null);
        $phone = $this->value($providerData['enrichment']['phone'] ?? null)
            ?? $this->value($providerData['listing']['phone'] ?? null);
        $contactMethod = $this->preferredContactMethod($email, $phone);
        $score = $this->scoreCandidate($providerData, $name, $role, $email, $phone);
        $needsHumanReview = $score < self::CONFIDENCE_THRESHOLD || $contactMethod === '';

        return $this->outputRow(
            $company,
            $needsHumanReview ? '' : $name,
            $needsHumanReview ? '' : $role,
            $needsHumanReview ? '' : $contactMethod,
            $score,
            implode('; ', $sourceEntries),
            $needsHumanReview,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function writeCsv(string $path, array $rows): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create output directory: {$directory}");
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to write output CSV: {$path}");
        }

        $headers = [
            'company_name',
            'mailing_address',
            'contact_name',
            'contact_role',
            'contact_email_or_phone',
            'confidence_score',
            'source',
            'needs_human_review',
        ];

        fputcsv($handle, $headers, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv(
                $handle,
                array_map(
                    static fn (string $header): string => is_bool($row[$header])
                        ? ($row[$header] ? 'true' : 'false')
                        : (string) $row[$header],
                    $headers,
                ),
                ',',
                '"',
                '',
            );
        }

        fclose($handle);
    }

    /**
     * @param  array<string, string>  $company
     * @return array<string, mixed>
     */
    private function outputRow(
        array $company,
        string $contactName,
        string $contactRole,
        string $contactMethod,
        int $score,
        string $source,
        bool $needsHumanReview,
    ): array {
        return [
            'company_name' => (string) ($company['company_name'] ?? ''),
            'mailing_address' => (string) ($company['mailing_address'] ?? ''),
            'contact_name' => $contactName,
            'contact_role' => $contactRole,
            'contact_email_or_phone' => $contactMethod,
            'confidence_score' => $score,
            'source' => $source,
            'needs_human_review' => $needsHumanReview,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $providerData
     * @return array<int, string>
     */
    private function sourceEntries(array $providerData): array
    {
        $sources = [];

        foreach (self::PROVIDERS as $provider) {
            $sourceUrl = $this->value($providerData[$provider]['source_url'] ?? null);

            if ($sourceUrl !== null) {
                $sources[] = "{$provider}:{$sourceUrl}";
            }
        }

        return $sources;
    }

    /**
     * @param  array<string, array<string, mixed>>  $providerData
     */
    private function bestName(array $providerData): string
    {
        $registryName = $this->value($providerData['registry']['name'] ?? null);
        $registryRole = $this->value($providerData['registry']['role'] ?? null);
        $listingName = $this->cleanName($this->value($providerData['listing']['name'] ?? null));

        if ($registryName !== null && ! $this->textContains($registryRole, ['registered agent'])) {
            return $this->cleanName($registryName);
        }

        if ($listingName !== '') {
            return $listingName;
        }

        return $registryName === null ? '' : $this->cleanName($registryName);
    }

    /**
     * @param  array<string, array<string, mixed>>  $providerData
     */
    private function bestRole(array $providerData): string
    {
        $registryRole = $this->value($providerData['registry']['role'] ?? null);
        $listingName = $this->value($providerData['listing']['name'] ?? null);
        $email = $this->value($providerData['enrichment']['email'] ?? null);

        return $this->canonicalRole($registryRole)
            ?? $this->canonicalRole($listingName)
            ?? $this->canonicalRoleFromEmail($email)
            ?? '';
    }

    private function canonicalRole(?string $text): ?string
    {
        if ($this->textContains($text, ['accounts payable', 'ap manager', 'billing'])) {
            return 'Accounts Payable';
        }

        if ($this->textContains($text, ['owner', 'founder', 'president', 'managing director'])) {
            return 'Owner';
        }

        if ($this->textContains($text, ['cfo', 'controller', 'finance'])) {
            return 'Finance Lead';
        }

        if ($this->textContains($text, ['office manager', 'manager'])) {
            return 'Office Manager';
        }

        if ($this->textContains($text, ['registered agent'])) {
            return 'Registered Agent';
        }

        return null;
    }

    private function canonicalRoleFromEmail(?string $email): ?string
    {
        $local = $this->emailLocal($email);

        if ($local === null) {
            return null;
        }

        if (str_contains($local, 'accounts') || str_contains($local, 'payable') || str_contains($local, 'billing')) {
            return 'Accounts Payable';
        }

        if (str_contains($local, 'finance')) {
            return 'Finance Lead';
        }

        if (in_array($local, ['admin', 'contact', 'hello', 'info', 'office', 'service', 'support'], true)) {
            return 'Office Manager';
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $providerData
     */
    private function scoreCandidate(
        array $providerData,
        string $name,
        string $role,
        ?string $email,
        ?string $phone,
    ): int {
        $sourceCount = count($this->sourceEntries($providerData));

        if ($sourceCount === 0) {
            return 0;
        }

        $score = 20; // The fixture is keyed by exact input company name.
        $score += min(24, $sourceCount * 8);
        $score += $this->roleScore($role);

        if ($email !== null) {
            $score += $this->isGenericEmail($email) ? 6 : 15;
        }

        if ($phone !== null) {
            $score += 10;
        }

        if ($email !== null && $phone !== null) {
            $score += 5;
        }

        $score += $this->providerConfidenceScore($providerData['enrichment']['provider_confidence'] ?? null);

        $registryName = $this->value($providerData['registry']['name'] ?? null);
        $listingName = $this->value($providerData['listing']['name'] ?? null);

        if ($this->namesAreEquivalent($registryName, $listingName)) {
            $score += 15;
        } elseif ($this->namesCouldReferToSamePerson($registryName, $listingName)) {
            $score += 8;
        } elseif ($this->namesConflict($registryName, $listingName)) {
            $score -= 25;
        }

        if ($this->phonesAgree($providerData)) {
            $score += 10;
        }

        if ($email !== null && $name !== '' && $this->emailMatchesName($email, $name)) {
            $score += 8;
        }

        if ($this->isGenericEmail($email)) {
            $score -= 12;
        }

        if ($name === '') {
            $score -= 10;
        }

        if ($role === '') {
            $score -= 8;
        }

        if ($sourceCount === 1) {
            $score -= 20;
        }

        if ($this->isWeakEnrichmentOnly($providerData, $sourceCount)) {
            $score -= 15;
        }

        if ($this->hasListingAndWeakEnrichmentWithoutRegistry($providerData)) {
            $score -= 25;
        }

        if ($email === null && $phone === null) {
            $score -= 25;
        }

        return max(0, min($this->maxScoreForSourceCoverage($sourceCount), $score));
    }

    private function roleScore(string $role): int
    {
        return match ($role) {
            'Accounts Payable' => 26,
            'Owner' => 22,
            'Finance Lead' => 18,
            'Office Manager' => 12,
            'Registered Agent' => 4,
            default => 0,
        };
    }

    private function providerConfidenceScore(mixed $providerConfidence): int
    {
        if (! is_numeric($providerConfidence)) {
            return 0;
        }

        $confidence = (int) $providerConfidence;

        return match (true) {
            $confidence >= 90 => 10,
            $confidence >= 80 => 8,
            $confidence >= 70 => 6,
            $confidence >= 60 => 3,
            default => 0,
        };
    }

    private function maxScoreForSourceCoverage(int $sourceCount): int
    {
        return $sourceCount >= 3 ? 100 : 95;
    }

    /**
     * @param  array<string, array<string, mixed>>  $providerData
     */
    private function phonesAgree(array $providerData): bool
    {
        $listingPhone = $this->normalizePhone($this->value($providerData['listing']['phone'] ?? null));
        $enrichmentPhone = $this->normalizePhone($this->value($providerData['enrichment']['phone'] ?? null));

        return $listingPhone !== '' && $listingPhone === $enrichmentPhone;
    }

    /**
     * @param  array<string, array<string, mixed>>  $providerData
     */
    private function isWeakEnrichmentOnly(array $providerData, int $sourceCount): bool
    {
        if ($sourceCount !== 1 || ! isset($providerData['enrichment'])) {
            return false;
        }

        $providerConfidence = $providerData['enrichment']['provider_confidence'] ?? 0;

        return is_numeric($providerConfidence) && (int) $providerConfidence < self::CONFIDENCE_THRESHOLD;
    }

    /**
     * @param  array<string, array<string, mixed>>  $providerData
     */
    private function hasListingAndWeakEnrichmentWithoutRegistry(array $providerData): bool
    {
        if (isset($providerData['registry']) || ! isset($providerData['listing'], $providerData['enrichment'])) {
            return false;
        }

        $providerConfidence = $providerData['enrichment']['provider_confidence'] ?? 0;

        return is_numeric($providerConfidence) && (int) $providerConfidence < self::CONFIDENCE_THRESHOLD;
    }

    private function preferredContactMethod(?string $email, ?string $phone): string
    {
        if ($email !== null && ! $this->isGenericEmail($email)) {
            return $email;
        }

        if ($phone !== null) {
            return $phone;
        }

        return $email ?? '';
    }

    private function isGenericEmail(?string $email): bool
    {
        $local = $this->emailLocal($email);

        if ($local === null) {
            return false;
        }

        $firstToken = preg_split('/[._+-]/', $local)[0] ?? $local;

        return in_array($local, self::GENERIC_EMAIL_LOCALS, true)
            || in_array($firstToken, self::GENERIC_EMAIL_LOCALS, true);
    }

    private function emailMatchesName(string $email, string $name): bool
    {
        $local = $this->emailLocal($email);

        if ($local === null) {
            return false;
        }

        $parts = array_map(
            fn (string $part): string => $this->canonicalFirstName($part),
            array_values(array_filter(preg_split('/[._+-]/', $local) ?: [])),
        );
        $nameParts = $this->nameParts($name);

        if ($nameParts === []) {
            return false;
        }

        $firstName = $this->canonicalFirstName($nameParts[0]);
        $lastName = $nameParts[count($nameParts) - 1] ?? '';

        return in_array($firstName, $parts, true)
            || ($lastName !== '' && in_array($lastName, $parts, true))
            || (in_array(substr($firstName, 0, 1), $parts, true) && $lastName !== '' && in_array($lastName, $parts, true));
    }

    private function namesAreEquivalent(?string $first, ?string $second): bool
    {
        $firstParts = $this->nameParts((string) $first);
        $secondParts = $this->nameParts((string) $second);

        return $firstParts !== [] && $firstParts === $secondParts;
    }

    private function namesCouldReferToSamePerson(?string $first, ?string $second): bool
    {
        $firstParts = $this->nameParts((string) $first);
        $secondParts = $this->nameParts((string) $second);

        if ($firstParts === [] || $secondParts === []) {
            return false;
        }

        $firstLast = $firstParts[count($firstParts) - 1];
        $secondLast = $secondParts[count($secondParts) - 1];

        if ($firstLast !== $secondLast) {
            return false;
        }

        $firstGiven = $this->canonicalFirstName($firstParts[0]);
        $secondGiven = $this->canonicalFirstName($secondParts[0]);

        return $firstGiven === $secondGiven || substr($firstGiven, 0, 1) === substr($secondGiven, 0, 1);
    }

    private function namesConflict(?string $first, ?string $second): bool
    {
        return $this->nameParts((string) $first) !== []
            && $this->nameParts((string) $second) !== []
            && ! $this->namesAreEquivalent($first, $second)
            && ! $this->namesCouldReferToSamePerson($first, $second);
    }

    /**
     * @return array<int, string>
     */
    private function nameParts(string $name): array
    {
        $normalized = preg_replace('/\b(dr|mr|mrs|ms)\b\.?/i', '', $this->cleanName($name));
        $normalized = strtolower((string) preg_replace('/[^a-z0-9 ]+/i', ' ', (string) $normalized));

        return array_values(array_filter(preg_split('/\s+/', trim($normalized)) ?: []));
    }

    private function cleanName(?string $name): string
    {
        $name = trim((string) $name);
        $name = preg_replace('/\s*\([^)]*\)\s*/', ' ', $name);

        return trim((string) preg_replace('/\s+/', ' ', (string) $name));
    }

    private function canonicalFirstName(string $name): string
    {
        $name = strtolower($name);

        return self::NICKNAMES[$name] ?? $name;
    }

    private function normalizePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?? '';
    }

    private function emailLocal(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        return strtolower((string) strstr($email, '@', true));
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function textContains(?string $text, array $needles): bool
    {
        $text = strtolower((string) $text);

        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function value(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
