<?php

use App\Http\Controllers\EnrichmentController;
use App\Http\Controllers\TrainingController;
use Illuminate\Support\Facades\Route;

Route::get('/', [EnrichmentController::class, 'index'])->name('enrichment.index');
Route::post('/enrichment/run', [EnrichmentController::class, 'run'])->name('enrichment.run');
Route::get('/enrichment/results', [EnrichmentController::class, 'results'])->name('enrichment.results');
Route::delete('/enrichment/runs/{runId}', [EnrichmentController::class, 'deleteRun'])->name('enrichment.deleteRun');
Route::post('/enrichment/verify', [EnrichmentController::class, 'verifyContact'])->name('enrichment.verify');

Route::get('/training', [TrainingController::class, 'index'])->name('training.index');
Route::post('/training/generate', [TrainingController::class, 'generate'])->name('training.generate');
Route::post('/training/calibrate', [TrainingController::class, 'calibrate'])->name('training.calibrate');
