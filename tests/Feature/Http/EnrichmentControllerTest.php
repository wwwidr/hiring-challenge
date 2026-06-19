<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

class EnrichmentControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanRunFiles();
    }

    protected function tearDown(): void
    {
        $this->cleanRunFiles();
        parent::tearDown();
    }

    public function test_index_page_loads(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Contact Enrichment');
        $response->assertSee('Run Pipeline');
    }

    public function test_run_with_default_data_redirects_to_results(): void
    {
        $response = $this->post('/enrichment/run', [
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('/enrichment/results', $response->headers->get('Location'));
    }

    public function test_results_page_shows_data_after_run(): void
    {
        $this->post('/enrichment/run', ['_token' => csrf_token()]);

        $response = $this->get('/enrichment/results');

        $response->assertStatus(200);
        $response->assertSee('Cedar Ridge Plumbing LLC');
        $response->assertSee('verified');
        $response->assertSee('Total');
    }

    public function test_results_page_has_explanation_in_modal(): void
    {
        $this->post('/enrichment/run', ['_token' => csrf_token()]);

        $response = $this->get('/enrichment/results');

        $response->assertStatus(200);
        $response->assertSee('provider(s) queried');
        $response->assertSee('Analysis');
    }

    public function test_results_page_supports_search_filter(): void
    {
        $this->post('/enrichment/run', ['_token' => csrf_token()]);

        $runId = $this->getLatestRunId();
        $response = $this->get("/enrichment/results?run={$runId}&search=cedar");

        $response->assertStatus(200);
        $response->assertSee('Cedar Ridge Plumbing LLC');
    }

    public function test_results_page_supports_sort(): void
    {
        $this->post('/enrichment/run', ['_token' => csrf_token()]);

        $response = $this->get('/enrichment/results?sort=company&dir=asc');

        $response->assertStatus(200);
    }

    public function test_results_without_runs_redirects(): void
    {
        $response = $this->get('/enrichment/results');

        $response->assertRedirect(route('enrichment.index'));
    }

    public function test_run_history_shown_on_index(): void
    {
        $this->post('/enrichment/run', ['_token' => csrf_token()]);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Previous Runs');
        $response->assertSee('companies.csv (demo)');
    }

    public function test_delete_run(): void
    {
        $this->post('/enrichment/run', ['_token' => csrf_token()]);

        $runId = $this->getLatestRunId();
        $this->assertNotNull($runId);

        $response = $this->delete("/enrichment/runs/{$runId}");
        $response->assertRedirect(route('enrichment.index'));

        $this->assertFileDoesNotExist(storage_path("enrichment_runs/{$runId}.json"));
    }

    public function test_verify_contact_updates_status(): void
    {
        $this->post('/enrichment/run', ['_token' => csrf_token()]);

        $runId = $this->getLatestRunId();
        $this->assertNotNull($runId);

        $response = $this->post('/enrichment/verify', [
            'run_id' => $runId,
            'company_name' => 'Coastal Breeze Pool Service',
        ]);

        $response->assertRedirect();

        $data = json_decode(file_get_contents(storage_path("enrichment_runs/{$runId}.json")), true);
        $coastal = collect($data['results'])->firstWhere('company_name', 'Coastal Breeze Pool Service');

        $this->assertEquals('verified', $coastal['verification_status']);
        $this->assertFalse($coastal['needs_human_review']);
        $this->assertTrue($coastal['manually_verified']);
    }

    private function cleanRunFiles(): void
    {
        $directory = storage_path('enrichment_runs');

        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*.json') as $file) {
            unlink($file);
        }
    }

    private function getLatestRunId(): ?string
    {
        $directory = storage_path('enrichment_runs');
        $files = glob($directory . '/*.json');

        if (empty($files)) {
            return null;
        }

        rsort($files);
        $data = json_decode(file_get_contents($files[0]), true);

        return $data['id'] ?? null;
    }
}
