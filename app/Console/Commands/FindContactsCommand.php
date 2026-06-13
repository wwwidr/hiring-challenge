<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\ContactFinder\DTOs\ResolvedContact;
use App\Modules\ContactFinder\Support\ContactResolverFactory;
use App\Modules\ContactFinder\Support\MockDataLoader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Reads the company CSV, cross-references the mocked providers, and writes an
 * enriched CSV with confidence + provenance + needs_human_review per row.
 *
 *   php artisan contacts:find
 *   php artisan contacts:find --input=... --mocks=... --output=...
 */
final class FindContactsCommand extends Command
{
    protected $signature = 'contacts:find
        {--input= : Path to the companies CSV (defaults to challenge/data/companies.csv)}
        {--mocks= : Path to the mock provider JSON (defaults to challenge/mocks/enrichment_responses.json)}
        {--output= : Where to write the enriched CSV (defaults to storage/app/contacts_found.csv)}';

    protected $description = 'Find decision-maker contacts from company name + address using the mocked providers';

    public function handle(): int
    {
        $input = $this->option('input') ?: base_path('challenge/data/companies.csv');
        $mocks = $this->option('mocks') ?: base_path('challenge/mocks/enrichment_responses.json');
        $output = $this->option('output') ?: storage_path('app/contacts_found.csv');

        try {
            $companies = $this->readCompanies($input);
            $resolver = ContactResolverFactory::fromMockData(MockDataLoader::load($mocks));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        /** @var list<ResolvedContact> $resolved */
        $resolved = [];
        foreach ($companies as [$name, $address]) {
            $resolved[] = $resolver->resolve($name, $address);
        }

        $this->writeCsv($output, $resolved);
        $this->renderSummary($resolved, $output);

        $emitted = count(array_filter($resolved, fn (ResolvedContact $c) => ! $c->needsHumanReview));
        Log::info('Contact finder run complete', [
            'companies' => count($resolved),
            'emitted' => $emitted,
            'needs_human_review' => count($resolved) - $emitted,
            'output' => $output,
        ]);

        return self::SUCCESS;
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    private function readCompanies(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Companies CSV not found: {$path}");
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Could not open CSV: {$path}");
        }

        $rows = [];
        $header = fgetcsv($handle, escape: ''); // discard header row
        if ($header !== false) {
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                if ($row === [null] || $row === false) {
                    continue; // blank line
                }
                $name = trim((string) ($row[0] ?? ''));
                $address = trim((string) ($row[1] ?? ''));
                if ($name !== '') {
                    $rows[] = [$name, $address];
                }
            }
        }
        fclose($handle);

        return $rows;
    }

    /** @param list<ResolvedContact> $resolved */
    private function writeCsv(string $path, array $resolved): void
    {
        @mkdir(dirname($path), 0775, true);
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Could not write output CSV: {$path}");
        }

        fputcsv($handle, ResolvedContact::columns(), escape: '');
        foreach ($resolved as $contact) {
            fputcsv($handle, array_values($contact->toRow()), escape: '');
        }
        fclose($handle);
    }

    /** @param list<ResolvedContact> $resolved */
    private function renderSummary(array $resolved, string $output): void
    {
        $emitted = array_filter($resolved, fn (ResolvedContact $c) => ! $c->needsHumanReview);

        $this->table(
            ['Company', 'Contact', 'Role', 'Score', 'Review?', 'Reason'],
            array_map(fn (ResolvedContact $c) => [
                $c->companyName,
                $c->contactName !== '' ? $c->contactName : '—',
                $c->contactRole !== '' ? $c->contactRole : '—',
                (string) $c->confidenceScore,
                $c->needsHumanReview ? 'yes' : 'no',
                $c->reason,
            ], $resolved),
        );

        $this->info(sprintf(
            '%d companies → %d auto-emitted (≥%d), %d to human review. Written to %s',
            count($resolved),
            count($emitted),
            \App\Modules\ContactFinder\Services\ConfidenceScorer::THRESHOLD,
            count($resolved) - count($emitted),
            $output,
        ));
    }
}
