<?php

namespace App\Modules\Company\Data;

use App\Modules\Company\Enums\CompanyStatus;
use Carbon\Carbon;
use Spatie\LaravelData\Data;

final class CompanyData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly CompanyStatus $status,
        public readonly Carbon $created_at,
    ) {}
}
