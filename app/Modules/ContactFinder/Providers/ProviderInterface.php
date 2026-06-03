<?php

namespace App\Modules\ContactFinder\Providers;

use App\Modules\ContactFinder\Data\ProviderSignal;

/**
 * Uniform contract over each (individually fallible) source. Mocked here; a
 * real registry/listing/enrichment client could implement the same interface.
 * A miss returns null rather than throwing.
 */
interface ProviderInterface
{
    public function name(): string;

    public function fetch(string $companyName): ?ProviderSignal;
}
