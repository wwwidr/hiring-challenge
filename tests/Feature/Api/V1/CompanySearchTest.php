<?php

namespace Tests\Feature\Api\V1;

use App\Modules\Company\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanySearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_companies_matching_query(): void
    {
        Company::factory()->create(['name' => 'Acme Corp']);
        Company::factory()->create(['name' => 'Beta Industries']);
        Company::factory()->create(['name' => 'Acme Labs']);

        $response = $this->getJson('/api/v1/companies/search?q=Acme');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonFragment(['name' => 'Acme Corp']);
        $response->assertJsonFragment(['name' => 'Acme Labs']);
        $response->assertJsonMissing(['name' => 'Beta Industries']);
    }

    public function test_search_requires_q_parameter(): void
    {
        $response = $this->getJson('/api/v1/companies/search');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('q');
    }

    public function test_search_requires_q_minimum_two_characters(): void
    {
        $response = $this->getJson('/api/v1/companies/search?q=a');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('q');
    }

    public function test_search_returns_empty_data_when_no_matches(): void
    {
        Company::factory()->create(['name' => 'Acme Corp']);

        $response = $this->getJson('/api/v1/companies/search?q=NonExistent');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_search_paginates_results_with_default_per_page(): void
    {
        Company::factory()->count(20)->create(['name' => 'TestCompany']);

        $response = $this->getJson('/api/v1/companies/search?q=TestCompany');

        $response->assertOk();
        $response->assertJsonCount(15, 'data');
        $response->assertJsonPath('meta.total', 20);
        $response->assertJsonPath('meta.per_page', 15);
    }

    public function test_search_respects_custom_per_page(): void
    {
        Company::factory()->count(10)->create(['name' => 'TestCompany']);

        $response = $this->getJson('/api/v1/companies/search?q=TestCompany&per_page=5');

        $response->assertOk();
        $response->assertJsonCount(5, 'data');
        $response->assertJsonPath('meta.total', 10);
        $response->assertJsonPath('meta.per_page', 5);
    }

    public function test_search_rejects_per_page_above_maximum(): void
    {
        $response = $this->getJson('/api/v1/companies/search?q=test&per_page=101');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('per_page');
    }

    public function test_search_rejects_per_page_below_minimum(): void
    {
        $response = $this->getJson('/api/v1/companies/search?q=test&per_page=0');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('per_page');
    }

    public function test_search_filters_by_active_status(): void
    {
        Company::factory()->create(['name' => 'Active Corp', 'status' => 'active']);
        Company::factory()->create(['name' => 'Inactive Corp', 'status' => 'inactive']);

        $response = $this->getJson('/api/v1/companies/search?q=Corp&status=active');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['name' => 'Active Corp']);
        $response->assertJsonMissing(['name' => 'Inactive Corp']);
    }

    public function test_search_filters_by_inactive_status(): void
    {
        Company::factory()->create(['name' => 'Active Corp', 'status' => 'active']);
        Company::factory()->create(['name' => 'Inactive Corp', 'status' => 'inactive']);

        $response = $this->getJson('/api/v1/companies/search?q=Corp&status=inactive');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['name' => 'Inactive Corp']);
        $response->assertJsonMissing(['name' => 'Active Corp']);
    }

    public function test_search_returns_all_statuses_by_default(): void
    {
        Company::factory()->create(['name' => 'Active Corp', 'status' => 'active']);
        Company::factory()->create(['name' => 'Inactive Corp', 'status' => 'inactive']);

        $response = $this->getJson('/api/v1/companies/search?q=Corp');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_search_rejects_invalid_status(): void
    {
        $response = $this->getJson('/api/v1/companies/search?q=test&status=invalid');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('status');
    }

    public function test_search_response_contains_expected_fields(): void
    {
        Company::factory()->create(['name' => 'Acme Corp', 'status' => 'active']);

        $response = $this->getJson('/api/v1/companies/search?q=Acme');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'name', 'status', 'created_at'],
            ],
        ]);
    }

    public function test_search_response_contains_pagination_metadata(): void
    {
        Company::factory()->count(5)->create(['name' => 'TestCompany']);

        $response = $this->getJson('/api/v1/companies/search?q=TestCompany');

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            'links',
        ]);
    }

    public function test_search_pagination_navigates_to_second_page(): void
    {
        Company::factory()->count(8)->create(['name' => 'TestCompany']);

        $page1 = $this->getJson('/api/v1/companies/search?q=TestCompany&per_page=3&page=1');
        $page2 = $this->getJson('/api/v1/companies/search?q=TestCompany&per_page=3&page=2');

        $page1->assertOk();
        $page1->assertJsonCount(3, 'data');
        $page1->assertJsonPath('meta.current_page', 1);

        $page2->assertOk();
        $page2->assertJsonCount(3, 'data');
        $page2->assertJsonPath('meta.current_page', 2);

        $page1Ids = collect($page1->json('data'))->pluck('id');
        $page2Ids = collect($page2->json('data'))->pluck('id');
        $this->assertEmpty($page1Ids->intersect($page2Ids), 'Pages should not contain overlapping results');
    }
}
