@php
    $currentSort = $filters['sort'] ?? 'score';
    $currentDir = $filters['dir'] ?? 'desc';
    $runParam = $currentRunId ?? null;

    $buildSortUrl = function (string $column) use ($currentSort, $currentDir, $filters, $runParam): string {
        $newDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
        $params = array_merge($filters, ['sort' => $column, 'dir' => $newDir, 'page' => 1]);
        if ($runParam) $params['run'] = $runParam;
        return route('enrichment.results', array_filter($params, fn($v) => $v !== ''));
    };

    $buildSortIcon = function (string $column) use ($currentSort, $currentDir): string {
        if ($currentSort !== $column) return '↕';
        return $currentDir === 'asc' ? '↑' : '↓';
    };
@endphp

<div class="space-y-4">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Total</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900">{{ $summary['total'] }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium text-emerald-600 uppercase tracking-wider">Verified</p>
            <p class="mt-1 text-2xl font-semibold text-emerald-700">{{ $summary['verified'] }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium text-amber-600 uppercase tracking-wider">Needs Review</p>
            <p class="mt-1 text-2xl font-semibold text-amber-700">{{ $summary['needs_review'] }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Avg Score</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900">{{ $summary['average_score'] }}</p>
        </div>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white p-4">
        <form method="GET" action="{{ route('enrichment.results') }}" class="flex flex-col sm:flex-row gap-3">
            <input type="hidden" name="sort" value="{{ $currentSort }}">
            <input type="hidden" name="dir" value="{{ $currentDir }}">
            @if($runParam)
                <input type="hidden" name="run" value="{{ $runParam }}">
            @endif

            <div class="flex-1">
                <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                       placeholder="Search company, contact, email, phone..."
                       class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm placeholder-gray-400 focus:border-gray-500 focus:ring-1 focus:ring-gray-500 focus:outline-none">
            </div>

            <select name="role"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 focus:border-gray-500 focus:ring-1 focus:ring-gray-500 focus:outline-none">
                <option value="">All Roles</option>
                @foreach($roles as $role)
                    <option value="{{ $role }}" {{ ($filters['role'] ?? '') === $role ? 'selected' : '' }}>{{ $role }}</option>
                @endforeach
            </select>

            <select name="status"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 focus:border-gray-500 focus:ring-1 focus:ring-gray-500 focus:outline-none">
                <option value="">All Statuses</option>
                @foreach($statuses as $status)
                    <option value="{{ $status }}" {{ ($filters['status'] ?? '') === $status ? 'selected' : '' }}>{{ $status }}</option>
                @endforeach
            </select>

            <button type="submit"
                    class="inline-flex items-center gap-1.5 rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 transition-colors">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
                Filter
            </button>

            @if(($filters['search'] ?? '') !== '' || ($filters['role'] ?? '') !== '' || ($filters['status'] ?? '') !== '')
                @php
                    $clearParams = $runParam ? ['run' => $runParam] : [];
                @endphp
                <a href="{{ route('enrichment.results', $clearParams) }}" class="inline-flex items-center px-3 py-2 text-sm text-gray-500 hover:text-gray-700">Clear</a>
            @endif
        </form>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <a href="{{ $buildSortUrl('company') }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                Company <span class="text-gray-300">{{ $buildSortIcon('company') }}</span>
                            </a>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <a href="{{ $buildSortUrl('contact') }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                Contact <span class="text-gray-300">{{ $buildSortIcon('contact') }}</span>
                            </a>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <a href="{{ $buildSortUrl('role') }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                Role <span class="text-gray-300">{{ $buildSortIcon('role') }}</span>
                            </a>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Phone</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <a href="{{ $buildSortUrl('score') }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                Score <span class="text-gray-300">{{ $buildSortIcon('score') }}</span>
                            </a>
                        </th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <a href="{{ $buildSortUrl('status') }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                Status <span class="text-gray-300">{{ $buildSortIcon('status') }}</span>
                            </a>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($results as $contactIndex => $contact)
                        <tr class="hover:bg-gray-50 transition-colors cursor-pointer"
                            onclick="document.getElementById('modal-{{ $contactIndex }}').classList.remove('hidden')">
                            <td class="px-4 py-3 text-sm font-medium text-gray-900 whitespace-nowrap">
                                {{ $contact['company_name'] }}
                                @if(!empty($contact['regulated_industry']))
                                    <span class="ml-1.5 inline-flex items-center rounded-full bg-purple-50 px-1.5 py-0.5 text-[10px] font-medium text-purple-700">
                                        {{ $contact['regulated_industry'] }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700 whitespace-nowrap">{{ $contact['contact_name'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">
                                {{ $contact['contact_role'] ?: '—' }}
                                @if(!empty($contact['all_roles']) && count($contact['all_roles']) > 1)
                                    <span class="ml-1 text-[10px] text-gray-400" title="Also: {{ implode(', ', array_keys($contact['all_roles'])) }}">
                                        +{{ count($contact['all_roles']) - 1 }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $contact['contact_email'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $contact['contact_phone'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-center whitespace-nowrap">
                                @php $score = $contact['confidence_score']; @endphp
                                <span class="inline-flex items-center justify-center w-10 h-6 rounded-full text-xs font-semibold
                                    {{ $score >= 70 ? 'bg-emerald-100 text-emerald-800' : ($score >= 40 ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-600') }}">
                                    {{ $score }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center whitespace-nowrap">
                                @php $status = $contact['verification_status']; @endphp
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium
                                    {{ $status === 'verified' ? 'bg-emerald-50 text-emerald-700' : '' }}
                                    {{ $status === 'unverified' ? 'bg-amber-50 text-amber-700' : '' }}
                                    {{ $status === 'conflicting' ? 'bg-red-50 text-red-700' : '' }}
                                    {{ $status === 'not_found' ? 'bg-gray-100 text-gray-500' : '' }}">
                                    @if($status === 'verified')
                                        <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
                                    @endif
                                    {{ $status }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-400">
                                No results match your filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(isset($pagination) && $pagination['total_pages'] > 1)
            <div class="flex items-center justify-between border-t border-gray-200 bg-gray-50 px-4 py-3">
                <p class="text-xs text-gray-500">
                    Showing {{ ($pagination['page'] - 1) * $pagination['per_page'] + 1 }}–{{ min($pagination['page'] * $pagination['per_page'], $pagination['total']) }}
                    of {{ $pagination['total'] }} results
                </p>
                <div class="flex gap-1">
                    @for($pageNumber = 1; $pageNumber <= $pagination['total_pages']; $pageNumber++)
                        @php
                            $pageParams = array_merge($filters, ['page' => $pageNumber]);
                            if ($runParam) $pageParams['run'] = $runParam;
                            $pageUrl = route('enrichment.results', array_filter($pageParams, fn($v) => $v !== ''));
                        @endphp
                        <a href="{{ $pageUrl }}"
                           class="inline-flex items-center justify-center w-8 h-8 rounded text-xs font-medium transition-colors
                                  {{ $pageNumber === $pagination['page'] ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-200' }}">
                            {{ $pageNumber }}
                        </a>
                    @endfor
                </div>
            </div>
        @endif
    </div>
</div>

@foreach($results as $contactIndex => $contact)
    <div id="modal-{{ $contactIndex }}" class="hidden fixed inset-0 z-50 overflow-y-auto" onclick="if(event.target===this) this.classList.add('hidden')">
        <div class="flex min-h-full items-center justify-center p-4">
            <div class="relative w-full max-w-2xl rounded-xl bg-white shadow-2xl ring-1 ring-gray-900/5">
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ $contact['company_name'] }}</h3>
                        <p class="mt-0.5 text-xs text-gray-400">Enrichment Details</p>
                    </div>
                    <button onclick="this.closest('[id^=modal-]').classList.add('hidden')"
                            class="rounded-lg p-1.5 text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="px-6 py-5 space-y-5">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-[10px] font-medium text-gray-400 uppercase tracking-wider">Contact</p>
                            <p class="mt-0.5 text-sm text-gray-900">{{ $contact['contact_name'] ?: '—' }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-medium text-gray-400 uppercase tracking-wider">Role</p>
                            <p class="mt-0.5 text-sm text-gray-900">{{ $contact['contact_role'] ?: '—' }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-medium text-gray-400 uppercase tracking-wider">Email</p>
                            <p class="mt-0.5 text-sm text-gray-900">{{ $contact['contact_email'] ?: '—' }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-medium text-gray-400 uppercase tracking-wider">Phone</p>
                            <p class="mt-0.5 text-sm text-gray-900">{{ $contact['contact_phone'] ?: '—' }}</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        @php $modalScore = $contact['confidence_score']; @endphp
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center justify-center w-12 h-8 rounded-full text-sm font-bold
                                {{ $modalScore >= 70 ? 'bg-emerald-100 text-emerald-800' : ($modalScore >= 40 ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-600') }}">
                                {{ $modalScore }}
                            </span>
                            <span class="text-xs text-gray-500">confidence</span>
                        </div>

                        @php $modalStatus = $contact['verification_status']; @endphp
                        <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium
                            {{ $modalStatus === 'verified' ? 'bg-emerald-50 text-emerald-700' : '' }}
                            {{ $modalStatus === 'unverified' ? 'bg-amber-50 text-amber-700' : '' }}
                            {{ $modalStatus === 'conflicting' ? 'bg-red-50 text-red-700' : '' }}
                            {{ $modalStatus === 'not_found' ? 'bg-gray-100 text-gray-500' : '' }}">
                            {{ $modalStatus }}
                        </span>

                        @if($contact['needs_human_review'])
                            <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700">
                                needs review
                            </span>
                        @endif
                    </div>

                    @if(!empty($contact['all_roles']) && count($contact['all_roles']) > 1)
                        <div>
                            <p class="text-[10px] font-medium text-gray-400 uppercase tracking-wider mb-1.5">All Roles Found</p>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($contact['all_roles'] as $roleName => $providerName)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-1 text-xs">
                                        <span class="font-medium text-gray-700">{{ $roleName }}</span>
                                        <span class="text-gray-400">{{ $providerName }}</span>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if(!empty($contact['provenance']))
                        <div>
                            <p class="text-[10px] font-medium text-gray-400 uppercase tracking-wider mb-1.5">Provenance</p>
                            <div class="space-y-1">
                                @foreach($contact['provenance'] as $field => $provenanceData)
                                    <div class="flex items-start gap-2 text-xs">
                                        <span class="font-medium text-gray-600 w-12 shrink-0">{{ $field }}</span>
                                        <span class="text-gray-500">{{ $provenanceData['value'] }}</span>
                                        <span class="text-gray-300 ml-auto shrink-0">
                                            {{ implode(', ', array_column($provenanceData['sources'], 'provider')) }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if(!empty($contact['explanation']))
                        <div>
                            <p class="text-[10px] font-medium text-gray-400 uppercase tracking-wider mb-1.5">Analysis</p>
                            <p class="text-sm text-gray-600 leading-relaxed">{{ $contact['explanation'] }}</p>
                        </div>
                    @endif
                </div>

                <div class="border-t border-gray-100 px-6 py-3 flex items-center justify-between">
                    <div>
                        @if(!empty($contact['manually_verified']))
                            <span class="inline-flex items-center gap-1 text-xs text-emerald-600">
                                <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
                                Manually verified {{ $contact['verified_at'] ?? '' }}
                            </span>
                        @elseif($contact['verification_status'] !== 'verified' && $contact['verification_status'] !== 'not_found' && $runParam)
                            <form method="POST" action="{{ route('enrichment.verify') }}" class="inline"
                                  onsubmit="return confirm('Mark this contact as verified?')">
                                @csrf
                                <input type="hidden" name="run_id" value="{{ $runParam }}">
                                <input type="hidden" name="company_name" value="{{ $contact['company_name'] }}">
                                <button type="submit"
                                        class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-500 transition-colors">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                    </svg>
                                    Mark as Verified
                                </button>
                            </form>
                        @endif
                    </div>
                    <button onclick="this.closest('[id^=modal-]').classList.add('hidden')"
                            class="rounded-md bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-200 transition-colors">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
@endforeach
