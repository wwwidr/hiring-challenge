<?php

namespace App\Modules\ContactFinder\Console;

use App\Modules\ContactFinder\Services\EnrichmentPipeline;
use App\Modules\ContactFinder\ValueObjects\ScoredContact;
use Illuminate\Console\Command;

class EnrichContactsCommand extends Command
{
    protected $signature = 'contacts:enrich
                            {csv : Path to the companies CSV file}
                            {--output=results.json : Path to the output JSON file}';

    protected $description = 'Enrich company contacts from CSV using mock providers and output scored results';

    public function __construct(
        private readonly EnrichmentPipeline $enrichmentPipeline,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $csvPath = $this->argument('csv');
        $outputPath = $this->option('output');

        if (!file_exists($csvPath)) {
            $this->error("CSV file not found: {$csvPath}");
            return self::FAILURE;
        }

        $this->info('Starting contact enrichment pipeline...');
        $this->newLine();

        $results = $this->enrichmentPipeline->process($csvPath);

        $this->displaySummaryTable($results);
        $this->writeJsonOutput($results, $outputPath);

        $this->newLine();
        $this->info("Results written to {$outputPath}");

        return self::SUCCESS;
    }

    /**
     * @param ScoredContact[] $results
     */
    private function displaySummaryTable(array $results): void
    {
        $rows = array_map(fn (ScoredContact $contact) => [
            $contact->companyName,
            $contact->contactName ?: '-',
            $contact->contactRole ?: '-',
            $contact->contactEmail ?: '-',
            $contact->contactPhone ?: '-',
            $contact->confidenceScore,
            $contact->verificationStatus->value,
            $contact->needsHumanReview ? 'YES' : 'NO',
        ], $results);

        $this->table(
            ['Company', 'Contact', 'Role', 'Email', 'Phone', 'Score', 'Status', 'Review'],
            $rows,
        );

        $total = count($results);
        $verified = count(array_filter($results, fn (ScoredContact $contact) => !$contact->needsHumanReview));
        $needsReview = $total - $verified;
        $averageScore = $total > 0
            ? round(array_sum(array_map(fn (ScoredContact $contact) => $contact->confidenceScore, $results)) / $total)
            : 0;

        $this->newLine();
        $this->info("Total: {$total} | Verified: {$verified} | Needs Review: {$needsReview} | Avg Score: {$averageScore}");
    }

    /**
     * @param ScoredContact[] $results
     */
    private function writeJsonOutput(array $results, string $outputPath): void
    {
        $output = array_map(fn (ScoredContact $contact) => $contact->toArray(), $results);

        file_put_contents(
            $outputPath,
            json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }
}
