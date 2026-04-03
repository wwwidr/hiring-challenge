<?php

namespace App\Modules\Company\Actions;

use App\Modules\Company\Data\CompanySearchCriteria;
use App\Modules\Company\Enums\CompanyStatusFilter;
use App\Modules\Company\Models\Company;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class SearchCompanies
{
    public function execute(CompanySearchCriteria $criteria): LengthAwarePaginator
    {
        $statusValue = $criteria->status === CompanyStatusFilter::All
            ? null
            : $criteria->status->value;

        return Company::query()
            ->search($criteria->q)
            ->withStatus($statusValue)
            ->orderBy('name')
            ->paginate($criteria->perPage);
    }
}
