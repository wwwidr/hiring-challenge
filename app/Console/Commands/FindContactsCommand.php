<?php

namespace App\Console\Commands;

use App\Modules\ContactFinder\ContactFinderService;
use App\Modules\ContactFinder\Data\ContactResult;
use App\Modules\ContactFinder\SuppressionList;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Reads the company CSV, resolves a decision-maker contact against the mocked
 * providers, and writes auditable JSON + CSV output plus a console summary.
 *
 * Example:
 *   php artisan contacts:find
 *   php artisan contacts:find --limit=5 --suppress=storage/app/suppression.txt
 */
class FindContactsCommand extends Command
{
    protected $signature = 'contacts:find
        {--input= : Path to the companies CSV (default: challenge/data/companies.csv)}
        {--mocks= : Path to the mock provider JSON (default: challenge/mocks/enrichment_responses.json)}
        {--out= : Path to write the JSON output (default: storage/app/contacts.json)}
        {--csv= : Path to write the CSV output (default: storage/app/contacts.csv)}
        {--suppress= : Optional path to an opt-out / do-not-contact list (one company per line)}
        {--threshold=70 : Confidence cutoff below which a row needs human review}
        {--limit= : Only process the first N rows}';

    protected $description = 'Find a verifiable decision-maker contact per company against the mocked providers';

    public function handle(): int
    {
        $input = $this->option('input') ?: base_path('challenge/data/companies.csv');
        $mocks = $this->option('mocks') ?: base_path('challenge/mocks/enrichment_responses.json');
        $out = $this->option('out') ?: storage_path('app/contacts.json');
        $csv = $this->option('csv') ?: storage_path('app/contacts.csv');
        $threshold = (int) $this->option('threshold');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        try {
            $companies = $this->readCompanies($input, $limit);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $suppression = SuppressionList::fromFile($this->option('suppress') ?: null);
        $service = ContactFinderService::fromMockFile($mocks, $suppression, $threshold);

        $this->components->info(sprintf(
            'Processing %d companies (threshold %d, precision-first).',
            count($companies),
            $threshold,
        ));

        $results = $service->run($companies);

        $this->renderTable($results);
        $this->writeJson($out, $results, $threshold);
        $this->writeCsv($csv, $results);
        $this->renderSummary($results, $out, $csv);

        return self::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function readCompanies(string $path, ?int $limit): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Companies CSV not found at: {$path}");
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Unable to open CSV at: {$path}");
        }

        $companies = [];
        $header = fgetcsv($handle);
        $nameIndex = is_array($header) ? array_search('company_name', $header, true) : false;
        if ($nameIndex === false) {
            $nameIndex = 0; // fall back to the first column
        }

        while (($row = fgetcsv($handle)) !== false) {
            $name = trim((string) ($row[$nameIndex] ?? ''));
            if ($name !== '') {
                $companies[] = $name;
            }
            if ($limit !== null && count($companies) >= $limit) {
                break;
            }
        }
        fclose($handle);

        return $companies;
    }

    /**
     * @param  ContactResult[]  $results
     */
    private function renderTable(array $results): void
    {
        $rows = [];
        foreach ($results as $i => $result) {
            $rows[] = [
                $i + 1,
                $this->truncate($result->companyName, 26),
                $result->confidenceScore,
                $result->needsHumanReview ? 'yes' : 'no',
                $this->truncate($this->note($result), 46),
            ];
        }

        $this->table(['#', 'Company', 'Score', 'Review', 'Contact / note'], $rows);
    }

    private function note(ContactResult $result): string
    {
        if (! $result->needsHumanReview && $result->contactEmailOrPhone !== '') {
            $role = $result->contactRole !== '' ? $result->contactRole.': ' : '';

            return $role.$result->contactEmailOrPhone;
        }

        if ($result->sources === [] && $result->confidenceScore === 0 && ! $result->needsHumanReview) {
            return 'suppressed (opt-out)';
        }

        if ($result->sources === []) {
            return 'no source returned data';
        }

        return 'needs review ('.implode('+', $result->sources).')';
    }

    /**
     * @param  ContactResult[]  $results
     */
    private function writeJson(string $path, array $results, int $threshold): void
    {
        $this->ensureDirectory($path);

        $emitted = $this->countEmitted($results);
        $suppressed = $this->countSuppressed($results);

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'threshold' => $threshold,
            'total' => count($results),
            'emitted' => $emitted,
            'needs_human_review' => count($results) - $emitted - $suppressed,
            'suppressed' => $suppressed,
            'results' => array_map(fn (ContactResult $r) => $r->toArray(), $results),
        ];

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    /**
     * @param  ContactResult[]  $results
     */
    private function writeCsv(string $path, array $results): void
    {
        $this->ensureDirectory($path);

        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new RuntimeException("Unable to write CSV at: {$path}");
        }

        $first = $results[0] ?? null;
        if ($first !== null) {
            fputcsv($handle, array_keys($first->toCsvRow()));
            foreach ($results as $result) {
                fputcsv($handle, $result->toCsvRow());
            }
        }
        fclose($handle);
    }

    /**
     * @param  ContactResult[]  $results
     */
    private function renderSummary(array $results, string $out, string $csv): void
    {
        $total = count($results);
        $emitted = $this->countEmitted($results);
        $suppressed = $this->countSuppressed($results);
        $review = $total - $emitted - $suppressed;

        $this->newLine();
        $this->components->info(sprintf(
            '%d companies: %d confident contact(s), %d need human review, %d suppressed.',
            $total,
            $emitted,
            $review,
            $suppressed,
        ));
        $this->components->twoColumnDetail('JSON output', $out);
        $this->components->twoColumnDetail('CSV output', $csv);
    }

    /**
     * @param  ContactResult[]  $results
     */
    private function countEmitted(array $results): int
    {
        return count(array_filter($results, fn (ContactResult $r) => ! $r->needsHumanReview && $r->contactEmailOrPhone !== ''));
    }

    /**
     * @param  ContactResult[]  $results
     */
    private function countSuppressed(array $results): int
    {
        return count(array_filter($results, fn (ContactResult $r) => ! $r->needsHumanReview && $r->contactEmailOrPhone === '' && $r->sources === []));
    }

    private function ensureDirectory(string $path): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    private function truncate(string $value, int $length): string
    {
        return strlen($value) > $length ? substr($value, 0, $length - 1).'…' : $value;
    }
}
