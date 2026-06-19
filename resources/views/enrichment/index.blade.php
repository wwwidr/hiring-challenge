@extends('layouts.app')

@section('title', 'Contact Enrichment')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-xl font-semibold text-gray-900">Contact Enrichment</h1>
        <p class="mt-1 text-sm text-gray-500">Upload a CSV of companies or use the demo data to run the enrichment pipeline.</p>
    </div>

    <form method="POST" action="{{ route('enrichment.run') }}" enctype="multipart/form-data">
        @csrf
        <div class="rounded-lg border border-gray-200 bg-white p-6">
            <div class="flex flex-col sm:flex-row items-start sm:items-end gap-4">
                <div class="flex-1">
                    <label for="csv_file" class="block text-sm font-medium text-gray-700 mb-1.5">Companies CSV</label>
                    <input type="file" name="csv_file" id="csv_file" accept=".csv"
                           class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border file:border-gray-300 file:text-sm file:font-medium file:bg-white file:text-gray-700 hover:file:bg-gray-50 file:cursor-pointer">
                    <p class="mt-1.5 text-xs text-gray-400">Leave empty to use demo data ({{ $defaultCompanyCount }} companies)</p>
                </div>
                <button type="submit"
                        class="inline-flex items-center gap-2 rounded-md bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-gray-800 transition-colors">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" />
                    </svg>
                    Run Pipeline
                </button>
            </div>
        </div>
    </form>

    @if(!empty($history ?? []))
        <div class="rounded-lg border border-gray-200 bg-white overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100">
                <h2 class="text-sm font-medium text-gray-700">Previous Runs</h2>
            </div>
            <ul class="divide-y divide-gray-100">
                @foreach($history as $run)
                    <li class="flex items-center justify-between px-4 py-2.5 hover:bg-gray-50 transition-colors {{ ($currentRunId ?? '') === $run['id'] ? 'bg-gray-50 ring-1 ring-inset ring-gray-200' : '' }}">
                        <a href="{{ route('enrichment.results', ['run' => $run['id']]) }}"
                           class="flex-1 flex items-center gap-4 min-w-0">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 truncate">{{ $run['csv_file'] }}</p>
                                <p class="text-xs text-gray-400">{{ $run['created_at'] }}</p>
                            </div>
                            <div class="flex items-center gap-3 text-xs text-gray-500 shrink-0">
                                <span>{{ $run['total'] }} total</span>
                                <span class="text-emerald-600">{{ $run['verified'] }} verified</span>
                            </div>
                        </a>
                        <form method="POST" action="{{ route('enrichment.deleteRun', $run['id']) }}" class="ml-3"
                              onsubmit="return confirm('Delete this run?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="p-1.5 rounded text-gray-300 hover:text-red-500 hover:bg-red-50 transition-colors" title="Delete run">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                </svg>
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(isset($results))
        @if(isset($currentRunMeta))
            <div class="flex items-center gap-2 text-xs text-gray-400">
                <span>Viewing: <strong class="text-gray-600">{{ $currentRunMeta['csv_file'] ?? 'unknown' }}</strong></span>
                <span>&middot;</span>
                <span>{{ $currentRunMeta['created_at'] ?? '' }}</span>
            </div>
        @endif

        @include('enrichment.results', [
            'results' => $results,
            'summary' => $summary,
            'pagination' => $pagination ?? null,
            'roles' => $roles ?? [],
            'statuses' => $statuses ?? [],
            'filters' => $filters ?? ['search' => '', 'role' => '', 'status' => '', 'sort' => 'score', 'dir' => 'desc'],
            'currentRunId' => $currentRunId ?? null,
        ])
    @endif

    @if(session('error'))
        <div class="rounded-md bg-red-50 border border-red-200 p-4">
            <p class="text-sm text-red-700">{{ session('error') }}</p>
        </div>
    @endif

    @if(session('success'))
        <div class="rounded-md bg-emerald-50 border border-emerald-200 p-4">
            <p class="text-sm text-emerald-700">{{ session('success') }}</p>
        </div>
    @endif
</div>
@endsection
