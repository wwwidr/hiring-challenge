<?php

namespace Tests\Feature;

use App\Jobs\EnrichContactJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EnrichContactTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // POST /api/enrich

    /** @test */
    public function enrich_endpoint_dispatches_job_and_returns_202_with_job_id(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/enrich', ['company_id' => 'FR-482-910-231']);

        $response->assertStatus(202);
        $response->assertJsonStructure(['job_id']);
        Queue::assertPushed(EnrichContactJob::class);
    }

    /** @test */
    public function enrich_endpoint_rejects_missing_company_id(): void
    {
        $response = $this->postJson('/api/enrich', []);

        $response->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // GET /api/enrich/{jobId}

    /** @test */
    public function polling_pending_job_returns_pending_status(): void
    {
        $jobId = 'test-job-id-001';
        Cache::put("enrich:{$jobId}", ['status' => 'pending'], now()->addHour());

        $response = $this->getJson("/api/enrich/{$jobId}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'pending']);
    }

    /** @test */
    public function polling_complete_job_returns_full_enriched_result(): void
    {
        $jobId = 'test-job-id-002';
        Cache::put("enrich:{$jobId}", [
            'status' => 'complete',
            'data'   => [
                'company'     => ['name' => ['value' => 'Dupont Industries SAS', 'confidence' => 'high', 'source' => 'provider_a']],
                'contacts'    => ['emails' => [], 'phones' => []],
                'key_people'  => [],
            ],
        ], now()->addHour());

        $response = $this->getJson("/api/enrich/{$jobId}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'complete']);
        $response->assertJsonPath('data.company.name.confidence', 'high');
    }

    /** @test */
    public function polling_unknown_job_id_returns_404(): void
    {
        $response = $this->getJson('/api/enrich/nonexistent-job-id');

        $response->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // Full end-to-end: dispatch and run job synchronously

    /** @test */
    public function end_to_end_enrichment_produces_confidence_tagged_result(): void
    {
        $jobId     = 'e2e-test-job-001';
        $companyId = 'FR-482-910-231';

        // Run the job synchronously (no queue)
        $job = new EnrichContactJob($jobId, $companyId);
        $job->handle();

        $result = Cache::get("enrich:{$jobId}");

        $this->assertSame('complete', $result['status']);
        $this->assertArrayHasKey('company', $result['data']);
        $this->assertArrayHasKey('contacts', $result['data']);
        $this->assertArrayHasKey('key_people', $result['data']);

        // Every top-level company field must have confidence and source
        $name = $result['data']['company']['name'];
        $this->assertArrayHasKey('value', $name);
        $this->assertArrayHasKey('confidence', $name);
        $this->assertArrayHasKey('source', $name);

        // High-confidence fields come from Provider A
        $this->assertSame('high', $name['confidence']);
    }

    /** @test */
    public function response_never_contains_negative_user_facing_copy(): void
    {
        $jobId = 'copy-test-job-001';
        Cache::put("enrich:{$jobId}", [
            'status'  => 'unavailable',
            'message' => 'Enrichment is being retried. Please check back shortly.',
        ], now()->addHour());

        $response = $this->getJson("/api/enrich/{$jobId}");
        $body = $response->getContent();

        // Hard rule from CLAUDE.md: no negative copy
        $this->assertStringNotContainsStringIgnoringCase('failed', $body);
        $this->assertStringNotContainsStringIgnoringCase('rejected', $body);
        $this->assertStringNotContainsStringIgnoringCase('denied', $body);
        $this->assertStringNotContainsStringIgnoringCase('error', $body);
    }
}
