<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\ContactFinder\ContactFinder;
use Illuminate\Console\Command;

class RunContactFinder extends Command
{
    protected $signature = 'contact-finder:run
        {--companies=challenge/data/companies.csv : Input company CSV}
        {--mocks=challenge/mocks/enrichment_responses.json : Mock provider response JSON}
        {--output=challenge/output/contact_finder_results.csv : Output CSV path}';

    protected $description = 'Resolve contact candidates from the Contact Finder mock providers.';

    public function handle(ContactFinder $finder): int
    {
        $companiesPath = $this->absolutePath((string) $this->option('companies'));
        $mocksPath = $this->absolutePath((string) $this->option('mocks'));
        $outputPath = $this->absolutePath((string) $this->option('output'));

        $results = $finder->run($companiesPath, $mocksPath, $outputPath);

        $this->info(sprintf('Wrote %d rows to %s', count($results), $outputPath));

        return self::SUCCESS;
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }
}
