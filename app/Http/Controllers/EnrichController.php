<?php

namespace App\Http\Controllers;

use App\Jobs\EnrichContactJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class EnrichController extends Controller
{
    /**
     * POST /api/enrich
     * Dispatch enrichment job and return job_id for polling.
     */
    public function enrich(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => ['required', 'string', 'max:100'],
        ]);

        $jobId = Str::uuid()->toString();

        // Mark as pending before dispatch so polls don't return 404
        Cache::put("enrich:{$jobId}", ['status' => 'pending'], now()->addHour());

        EnrichContactJob::dispatch($jobId, $request->input('company_id'));

        return response()->json(['job_id' => $jobId], 202);
    }

    /**
     * GET /api/enrich/{jobId}
     * Poll for enrichment result.
     */
    public function result(string $jobId): JsonResponse
    {
        $result = Cache::get("enrich:{$jobId}");

        if ($result === null) {
            return response()->json([
                'status'  => 'not_found',
                'message' => 'No enrichment task found for this ID.',
            ], 404);
        }

        return response()->json($result);
    }
}
