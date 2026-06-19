<?php

namespace App\Modules\ContactFinder\Services;

use App\Modules\ContactFinder\Contracts\ContactProviderInterface;
use App\Modules\ContactFinder\ValueObjects\NormalizedCompany;
use App\Modules\ContactFinder\ValueObjects\ScoredContact;
use Illuminate\Support\Facades\Log;

class EnrichmentPipeline
{
    /** @var ContactProviderInterface[] */
    private readonly array $providers;

    /**
     * @param ContactProviderInterface[] $providers
     */
    public function __construct(
        private readonly CompanyNormalizer $companyNormalizer,
        private readonly ContactMerger $contactMerger,
        private readonly CsvSanitizer $csvSanitizer,
        private readonly RateLimiter $rateLimiter,
        private readonly CircuitBreaker $circuitBreaker,
        array $providers,
    ) {
        $this->providers = $providers;
    }

    /**
     * @return ScoredContact[]
     */
    public function process(string $csvPath): array
    {
        $this->csvSanitizer->validateRowCount($csvPath);

        $companies = $this->ingest($csvPath);
        $companies = $this->resolve($companies);
        $enrichedResults = $this->enrich($companies);
        $scoredContacts = $this->score($companies, $enrichedResults);

        return $scoredContacts;
    }

    /**
     * Stage 1: Parse CSV, sanitize inputs, and normalize company names.
     *
     * @return NormalizedCompany[]
     */
    private function ingest(string $csvPath): array
    {
        $companies = [];
        $handle = fopen($csvPath, 'r');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open CSV file: {$csvPath}");
        }

        $headers = fgetcsv($handle);

        if ($headers === false) {
            fclose($handle);
            throw new \RuntimeException("CSV file is empty: {$csvPath}");
        }

        $nameIndex = array_search('company_name', $headers);
        $addressIndex = array_search('mailing_address', $headers);

        if ($nameIndex === false) {
            fclose($handle);
            throw new \RuntimeException('CSV file missing required column: company_name');
        }

        while (($row = fgetcsv($handle)) !== false) {
            $companyName = $this->csvSanitizer->sanitizeField($row[$nameIndex] ?? '');
            $address = $addressIndex !== false
                ? $this->csvSanitizer->sanitizeField($row[$addressIndex] ?? '')
                : '';

            if ($companyName === '') {
                continue;
            }

            $companies[] = $this->companyNormalizer->normalize($companyName, $address);
        }

        fclose($handle);

        Log::info('Enrichment pipeline: ingested companies', ['count' => count($companies)]);

        return $companies;
    }

    /**
     * Stage 2: Deduplicate companies by fingerprint.
     *
     * @param NormalizedCompany[] $companies
     * @return NormalizedCompany[]
     */
    private function resolve(array $companies): array
    {
        $deduplicated = $this->companyNormalizer->deduplicate($companies);

        $removedCount = count($companies) - count($deduplicated);

        if ($removedCount > 0) {
            Log::info('Enrichment pipeline: deduplicated companies', [
                'removed' => $removedCount,
                'remaining' => count($deduplicated),
            ]);
        }

        return $deduplicated;
    }

    /**
     * Stage 3: Query all providers for each company with rate limiting and circuit breaker.
     *
     * @param NormalizedCompany[] $companies
     * @return array<string, array<int, \App\Modules\ContactFinder\ValueObjects\ProviderResult|null>>
     */
    private function enrich(array $companies): array
    {
        $results = [];

        foreach ($companies as $company) {
            $companyResults = [];

            foreach ($this->providers as $provider) {
                $providerName = $provider->getName();

                if (!$this->circuitBreaker->isAvailable($providerName)) {
                    Log::warning('Provider circuit open, skipping', [
                        'company' => $company->originalName,
                        'provider' => $providerName,
                    ]);
                    $companyResults[] = null;

                    continue;
                }

                if (!$this->rateLimiter->attempt($providerName, maxAttempts: 100)) {
                    Log::warning('Provider rate limited, skipping', [
                        'company' => $company->originalName,
                        'provider' => $providerName,
                    ]);
                    $companyResults[] = null;

                    continue;
                }

                try {
                    $result = $provider->lookup($company);
                    $companyResults[] = $result;

                    $this->circuitBreaker->recordSuccess($providerName);

                    Log::debug('Provider lookup', [
                        'company' => $company->originalName,
                        'provider' => $providerName,
                        'found' => $result !== null,
                    ]);
                } catch (\Throwable $exception) {
                    $this->circuitBreaker->recordFailure($providerName);
                    $companyResults[] = null;

                    Log::error('Provider lookup exception', [
                        'company' => $company->originalName,
                        'provider' => $providerName,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $results[$company->originalName] = $companyResults;
        }

        return $results;
    }

    /**
     * Stage 4: Merge provider results, detect regulated industries, and score each contact.
     *
     * @param NormalizedCompany[] $companies
     * @param array<string, array<int, \App\Modules\ContactFinder\ValueObjects\ProviderResult|null>> $enrichedResults
     * @return ScoredContact[]
     */
    private function score(array $companies, array $enrichedResults): array
    {
        $scoredContacts = [];
        $regulatedIndustryDetector = new RegulatedIndustryDetector();

        foreach ($companies as $company) {
            $providerResults = $enrichedResults[$company->originalName] ?? [];
            $regulatedIndustry = $regulatedIndustryDetector->detect($company->originalName);
            $scoredContacts[] = $this->contactMerger->merge($company->originalName, $providerResults, $regulatedIndustry);
        }

        $verified = count(array_filter($scoredContacts, fn (ScoredContact $contact) => !$contact->needsHumanReview));
        $needsReview = count(array_filter($scoredContacts, fn (ScoredContact $contact) => $contact->needsHumanReview));

        Log::info('Enrichment pipeline: scoring complete', [
            'total' => count($scoredContacts),
            'verified' => $verified,
            'needs_review' => $needsReview,
        ]);

        return $scoredContacts;
    }
}
