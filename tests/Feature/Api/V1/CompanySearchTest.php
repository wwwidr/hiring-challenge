<?php

namespace Tests\Feature\Api\V1;

use App\Modules\Company\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanySearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_paginated_company_search_results(): void
    {
        $matchingActive = Company::factory()->create([
            'name' => 'Acme Logistics',
            'status' => 'active',
        ]);

        $matchingInactive = Company::factory()->inactive()->create([
            'name' => 'Acme Warehousing',
        ]);

        Company::factory()->create([
            'name' => 'Different Company',
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/v1/companies/search?q=Acme');

        $response->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 15)
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment([
                'id' => $matchingActive->id,
                'name' => 'Acme Logistics',
                'status' => 'active',
            ])
            ->assertJsonFragment([
                'id' => $matchingInactive->id,
                'name' => 'Acme Warehousing',
                'status' => 'inactive',
            ])
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'status', 'created_at'],
                ],
            ]);
    }

    public function test_it_returns_validation_errors_for_invalid_input(): void
    {
        $response = $this->getJson('/api/v1/companies/search?q=a&status=pending&per_page=101');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['q', 'status', 'per_page'])
            ->assertJsonPath('errors.q.0', 'The search term must be at least 2 characters.')
            ->assertJsonPath('errors.status.0', 'The status must be one of: active, inactive, all.')
            ->assertJsonPath('errors.per_page.0', 'The per_page parameter may not be greater than 100.');
    }

    public function test_it_returns_empty_results_when_no_companies_match(): void
    {
        Company::factory()->create([
            'name' => 'Northwind Traders',
        ]);

        $response = $this->getJson('/api/v1/companies/search?q=Acme');

        $response->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_it_paginates_results_using_the_requested_per_page_value(): void
    {
        Company::factory()->count(12)->sequence(
            fn ($sequence) => ['name' => 'Acme Company '.($sequence->index + 1)]
        )->create();

        $response = $this->getJson('/api/v1/companies/search?q=Acme&per_page=5&page=2');

        $response->assertOk()
            ->assertJsonPath('current_page', 2)
            ->assertJsonPath('per_page', 5)
            ->assertJsonPath('total', 12)
            ->assertJsonPath('last_page', 3)
            ->assertJsonCount(5, 'data');
    }

    public function test_it_filters_results_by_status(): void
    {
        $activeCompany = Company::factory()->create([
            'name' => 'Acme Active',
            'status' => 'active',
        ]);

        Company::factory()->inactive()->create([
            'name' => 'Acme Inactive',
        ]);

        $response = $this->getJson('/api/v1/companies/search?q=Acme&status=active');

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment([
                'id' => $activeCompany->id,
                'name' => 'Acme Active',
                'status' => 'active',
            ])
            ->assertJsonMissing([
                'name' => 'Acme Inactive',
            ]);
    }
}
