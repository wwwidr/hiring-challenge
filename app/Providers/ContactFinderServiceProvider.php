<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\ContactFinder\ContactFinder;
use App\Services\ContactFinder\ContactSelector;
use App\Services\ContactFinder\EmailValidator;
use App\Services\ContactFinder\NameMatcher;
use App\Services\ContactFinder\Normalizer;
use App\Services\ContactFinder\Scorer;
use Illuminate\Support\ServiceProvider;

final class ContactFinderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NameMatcher::class);
        $this->app->singleton(EmailValidator::class);
        $this->app->singleton(Normalizer::class);

        $this->app->singleton(ContactSelector::class, function ($app) {
            return new ContactSelector($app->make(NameMatcher::class));
        });

        $this->app->singleton(Scorer::class, function ($app) {
            return new Scorer(
                $app->make(NameMatcher::class),
                $app->make(EmailValidator::class),
            );
        });

        $this->app->singleton(ContactFinder::class, function ($app) {
            return new ContactFinder(
                $app->make(Normalizer::class),
                $app->make(ContactSelector::class),
                $app->make(Scorer::class),
            );
        });
    }
}
