<?php

declare(strict_types=1);

namespace App\Modules\ContactFinder\Contracts;

use App\Modules\ContactFinder\DTOs\ProviderResult;

/**
 * A single, independently fallible source of contact data.
 *
 * Production implementations would call a registry API, a maps listing, or an
 * enrichment vendor. For this slice they read the mocked fixtures. Either way
 * the contract is the same: given a company, return what you found, or null.
 */
interface ContactProvider
{
    /**
     * @return ProviderResult|null  Null means "this source found nothing"
     *                              (key absent, or no usable fields).
     */
    public function lookup(string $companyName): ?ProviderResult;

    /** Stable key used in the `source` provenance column (e.g. "registry"). */
    public function key(): string;
}
