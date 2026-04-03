<?php

namespace App\Modules\Company\Data;

use App\Modules\Company\Enums\CompanyStatusFilter;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;

final class CompanySearchCriteria extends Data
{
    public function __construct(
        #[Required, StringType, Min(2)]
        public readonly string $q,

        public readonly CompanyStatusFilter $status = CompanyStatusFilter::All,

        #[IntegerType, Min(1), Max(100), MapInputName('per_page')]
        public readonly int $perPage = 15,
    ) {}

    public function statusValue(): ?string
    {
        return $this->status === CompanyStatusFilter::All
            ? null
            : $this->status->value;
    }
}
