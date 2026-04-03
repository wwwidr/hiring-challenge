<?php

namespace Tests\Feature\Modules\Company\Actions;

use App\Modules\Company\Actions\SearchCompanies;
use App\Modules\Company\Data\CompanySearchCriteria;
use App\Modules\Company\Enums\CompanyStatusFilter;
use App\Modules\Company\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchCompaniesTest extends TestCase
{
    use RefreshDatabase;

    private SearchCompanies $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new SearchCompanies;
    }

    public function test_returns_companies_matching_search_term(): void
    {
        Company::factory()->create(['name' => 'Acme Corp']);
        Company::factory()->create(['name' => 'Beta Industries']);

        $criteria = new CompanySearchCriteria(q: 'Acme');
        $results = $this->action->execute($criteria);

        $this->assertCount(1, $results->items());
        $this->assertSame('Acme Corp', $results->items()[0]->name);
    }

    public function test_returns_empty_results_when_no_match(): void
    {
        Company::factory()->create(['name' => 'Acme Corp']);

        $criteria = new CompanySearchCriteria(q: 'NonExistent');
        $results = $this->action->execute($criteria);

        $this->assertCount(0, $results->items());
    }

    public function test_filters_by_status(): void
    {
        Company::factory()->create(['name' => 'Active Corp', 'status' => 'active']);
        Company::factory()->create(['name' => 'Inactive Corp', 'status' => 'inactive']);

        $criteria = new CompanySearchCriteria(q: 'Corp', status: CompanyStatusFilter::Active);
        $results = $this->action->execute($criteria);

        $this->assertCount(1, $results->items());
        $this->assertSame('Active Corp', $results->items()[0]->name);
    }

    public function test_returns_all_statuses_when_filter_is_all(): void
    {
        Company::factory()->create(['name' => 'Active Corp', 'status' => 'active']);
        Company::factory()->create(['name' => 'Inactive Corp', 'status' => 'inactive']);

        $criteria = new CompanySearchCriteria(q: 'Corp', status: CompanyStatusFilter::All);
        $results = $this->action->execute($criteria);

        $this->assertCount(2, $results->items());
    }

    public function test_paginates_results(): void
    {
        Company::factory()->count(10)->create(['name' => 'TestCompany']);

        $criteria = new CompanySearchCriteria(q: 'TestCompany', perPage: 3);
        $results = $this->action->execute($criteria);

        $this->assertCount(3, $results->items());
        $this->assertSame(10, $results->total());
    }

    public function test_orders_results_by_name(): void
    {
        Company::factory()->create(['name' => 'Charlie Corp']);
        Company::factory()->create(['name' => 'Alpha Corp']);
        Company::factory()->create(['name' => 'Bravo Corp']);

        $criteria = new CompanySearchCriteria(q: 'Corp');
        $results = $this->action->execute($criteria);

        $names = collect($results->items())->pluck('name')->toArray();
        $this->assertSame(['Alpha Corp', 'Bravo Corp', 'Charlie Corp'], $names);
    }
}
