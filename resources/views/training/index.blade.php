@extends('layouts.app')

@section('title', 'Weight Training')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-xl font-semibold text-gray-900">Weight Training</h1>
        <p class="mt-1 text-sm text-gray-500">
            Generate labeled data from the pipeline, validate contacts manually, then auto-calibrate weights.
        </p>
    </div>

    @if(session('success'))
        <div class="rounded-md bg-emerald-50 border border-emerald-200 p-4">
            <p class="text-sm text-emerald-700">{{ session('success') }}</p>
        </div>
    @endif

    @if(session('error'))
        <div class="rounded-md bg-red-50 border border-red-200 p-4">
            <p class="text-sm text-red-700">{{ session('error') }}</p>
        </div>
    @endif

    @if(!isset($trainingData))
        <form method="POST" action="{{ route('training.generate') }}" enctype="multipart/form-data">
            @csrf
            <div class="rounded-lg border border-gray-200 bg-white p-6 space-y-4">
                <div class="flex flex-col sm:flex-row items-start sm:items-end gap-4">
                    <div class="flex-1">
                        <label for="csv_file" class="block text-sm font-medium text-gray-700 mb-1.5">Companies CSV</label>
                        <input type="file" name="csv_file" id="csv_file" accept=".csv"
                               class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border file:border-gray-300 file:text-sm file:font-medium file:bg-white file:text-gray-700 hover:file:bg-gray-50 file:cursor-pointer">
                        <p class="mt-1.5 text-xs text-gray-400">Leave empty to use demo data.</p>
                    </div>
                    <div class="w-32">
                        <label for="percentage" class="block text-sm font-medium text-gray-700 mb-1.5">Sample %</label>
                        <input type="number" name="percentage" id="percentage" value="20" min="1" max="100" step="1"
                               class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 focus:border-gray-500 focus:ring-1 focus:ring-gray-500 focus:outline-none">
                        <p class="mt-1.5 text-xs text-gray-400">% of results for training</p>
                    </div>
                    <button type="submit"
                            class="inline-flex items-center gap-2 rounded-md bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-gray-800 transition-colors">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 0-6.23.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0 1 12 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" />
                        </svg>
                        Generate Training Set
                    </button>
                </div>
                <p class="text-xs text-gray-400">Cutoff threshold is fixed at <strong>70</strong>. Training only calibrates the confidence score weights, not the cutoff.</p>
            </div>
        </form>
    @else
        @include('training.validation', [
            'trainingData' => $trainingData,
            'currentWeights' => $currentWeights,
            'totalCompanies' => $totalCompanies,
            'samplePercentage' => $samplePercentage ?? 20,
        ])
    @endif
</div>
@endsection
