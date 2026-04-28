<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Company\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanySearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {   
        /* I have used inline validation rather than
         * a Form Request as the current project does
         * not use and mention Form Requests anywhere. 
        */
        
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:255'],
            'status' => ['nullable', 'string', 'in:active,inactive,all'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'q.required' => 'A search term is required.',
            'q.min' => 'The search term must be at least 2 characters.',
            'status.string' => 'The status must be a string.',
            'status.in' => 'The status must be one of: active, inactive, all.',
            'per_page.integer' => 'The per_page parameter must be an integer.',
            'per_page.min' => 'The per_page parameter must be at least 1.',
            'per_page.max' => 'The per_page parameter may not be greater than 100.',
        ]);

        $status = $validated['status'] ?? 'all';
        $perPage = $validated['per_page'] ?? 15;

        $companies = Company::query()
            ->select(['id', 'name', 'status', 'created_at'])
            ->search($validated['q'])
            ->withStatus($status)
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($companies);    
    }
}
