<?php

namespace App\Modules\ContactFinder\Contracts;

use App\Modules\ContactFinder\ValueObjects\NormalizedCompany;
use App\Modules\ContactFinder\ValueObjects\ProviderResult;

interface ContactProviderInterface
{
    public function getName(): string;

    public function getAuthorityWeight(): float;

    public function lookup(NormalizedCompany $company): ?ProviderResult;
}
