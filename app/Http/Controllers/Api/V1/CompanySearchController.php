<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Company\Actions\SearchCompanies;
use App\Modules\Company\Data\CompanyData;
use App\Modules\Company\Data\CompanySearchCriteria;
use Spatie\LaravelData\PaginatedDataCollection;
use Symfony\Component\HttpFoundation\Response;

final class CompanySearchController extends Controller
{
    public function __invoke(
        CompanySearchCriteria $criteria,
        SearchCompanies $action,
    ): Response {
        $results = $action->execute($criteria);

        return CompanyData::collect($results, PaginatedDataCollection::class)
            ->toResponse(request());
    }
}
