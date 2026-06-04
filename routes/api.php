<?php

use App\Http\Controllers\Api\V1\CompanySearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| API V1 routes. Candidates should add their endpoints here.
|
*/

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json(['status' => 'ok']));

    // TICKET-001: Add company search endpoint here
    Route::get('/companies/search', CompanySearchController::class);
});
