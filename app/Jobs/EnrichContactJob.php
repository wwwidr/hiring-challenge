<?php

namespace App\Jobs;

use App\Services\ContactNormaliser;
use App\Services\LlmExtractor;
use App\Services\MockProviderA;
use App\Services\MockProviderB;
use App\Services\MockProviderC;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EnrichContactJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly string $jobId,
        public readonly string $companyId,
    ) {}

    public function handle(): void
    {
        try {
            // 1. Fetch from all three providers (independent; could be parallelised)
            $dataA    = MockProviderA::fetch($this->companyId);
            $dataB    = MockProviderB::fetch($this->companyId);
            $snippets = MockProviderC::fetch($this->companyId);

            // 2. LLM extracts from unstructured text only — never calculates
            $llmData = LlmExtractor::extract($snippets);

            // 3. Deterministic merge and confidence scoring
            $result = ContactNormaliser::merge($dataA, $dataB, $llmData);

            // 4. Store result — frontend polls this key
            Cache::put("enrich:{$this->jobId}", [
                'status' => 'complete',
                'data'   => $result,
            ], now()->addHour());

        } catch (\Throwable $e) {
            Log::error('EnrichContactJob failed', [
                'job_id'     => $this->jobId,
                'company_id' => $this->companyId,
                'error'      => $e->getMessage(),
            ]);

            Cache::put("enrich:{$this->jobId}", [
                'status'  => 'unavailable',
                'message' => 'Enrichment is being retried. Please check back shortly.',
            ], now()->addHour());
        }
    }
}
