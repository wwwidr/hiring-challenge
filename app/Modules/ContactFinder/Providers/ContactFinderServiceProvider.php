<?php

namespace App\Modules\ContactFinder\Providers;

use App\Modules\ContactFinder\Console\CalibrateWeightsCommand;
use App\Modules\ContactFinder\Console\EnrichContactsCommand;
use App\Modules\ContactFinder\Contracts\ContactProviderInterface;
use App\Modules\ContactFinder\Services\CircuitBreaker;
use App\Modules\ContactFinder\Services\CompanyNormalizer;
use App\Modules\ContactFinder\Services\ConfidenceCalculator;
use App\Modules\ContactFinder\Services\ContactMerger;
use App\Modules\ContactFinder\Services\ContactValidator;
use App\Modules\ContactFinder\Services\CsvSanitizer;
use App\Modules\ContactFinder\Services\EnrichmentPipeline;
use App\Modules\ContactFinder\Services\RateLimiter;
use App\Modules\ContactFinder\Services\WeightCalibrator;
use Illuminate\Support\ServiceProvider;

class ContactFinderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CompanyNormalizer::class);
        $this->app->singleton(ConfidenceCalculator::class);
        $this->app->singleton(ContactMerger::class);
        $this->app->singleton(ContactValidator::class);
        $this->app->singleton(CsvSanitizer::class);
        $this->app->singleton(RateLimiter::class);
        $this->app->singleton(CircuitBreaker::class);
        $this->app->singleton(WeightCalibrator::class);

        $this->app->tag([
            MockRegistryProvider::class,
            MockListingProvider::class,
            MockEnrichmentProvider::class,
        ], ContactProviderInterface::class);

        $this->app->when([
            MockRegistryProvider::class,
            MockListingProvider::class,
            MockEnrichmentProvider::class,
        ])
            ->needs('$mockDataPath')
            ->give(fn () => config('enrichment.mock_data_path'));

        $this->app->singleton(EnrichmentPipeline::class, function ($app) {
            return new EnrichmentPipeline(
                companyNormalizer: $app->make(CompanyNormalizer::class),
                contactMerger: $app->make(ContactMerger::class),
                csvSanitizer: $app->make(CsvSanitizer::class),
                rateLimiter: $app->make(RateLimiter::class),
                circuitBreaker: $app->make(CircuitBreaker::class),
                providers: [
                    $app->make(MockRegistryProvider::class),
                    $app->make(MockListingProvider::class),
                    $app->make(MockEnrichmentProvider::class),
                ],
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                EnrichContactsCommand::class,
                CalibrateWeightsCommand::class,
            ]);
        }
    }
}
