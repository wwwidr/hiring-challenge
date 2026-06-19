<?php
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
?>

<div class="space-y-4">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Total</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900"><?php echo e($summary['total']); ?></p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium text-emerald-600 uppercase tracking-wider">Verified</p>
            <p class="mt-1 text-2xl font-semibold text-emerald-700"><?php echo e($summary['verified']); ?></p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium text-amber-600 uppercase tracking-wider">Needs Review</p>
            <p class="mt-1 text-2xl font-semibold text-amber-700"><?php echo e($summary['needs_review']); ?></p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Avg Score</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900"><?php echo e($summary['average_score']); ?></p>
        </div>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white p-4">
        <form method="GET" action="<?php echo e(route('enrichment.results')); ?>" class="flex flex-col sm:flex-row gap-3">
            <input type="hidden" name="sort" value="<?php echo e($currentSort); ?>">
            <input type="hidden" name="dir" value="<?php echo e($currentDir); ?>">
            <?php if($runParam): ?>
                <input type="hidden" name="run" value="<?php echo e($runParam); ?>">
            <?php endif; ?>

            <div class="flex-1">
                <input type="text" name="search" value="<?php echo e($filters['search'] ?? ''); ?>"
                       placeholder="Search company, contact, email, phone..."
                       class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm placeholder-gray-400 focus:border-gray-500 focus:ring-1 focus:ring-gray-500 focus:outline-none">
            </div>

            <select name="role"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 focus:border-gray-500 focus:ring-1 focus:ring-gray-500 focus:outline-none">
                <option value="">All Roles</option>
                <?php $__currentLoopData = $roles; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $role): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($role); ?>" <?php echo e(($filters['role'] ?? '') === $role ? 'selected' : ''); ?>><?php echo e($role); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </select>

            <select name="status"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 focus:border-gray-500 focus:ring-1 focus:ring-gray-500 focus:outline-none">
                <option value="">All Statuses</option>
                <?php $__currentLoopData = $statuses; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $status): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($status); ?>" <?php echo e(($filters['status'] ?? '') === $status ? 'selected' : ''); ?>><?php echo e($status); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </select>

            <button type="submit"
                    class="inline-flex items-center gap-1.5 rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 transition-colors">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
                Filter
            </button>

            <?php if(($filters['search'] ?? '') !== '' || ($filters['role'] ?? '') !== '' || ($filters['status'] ?? '') !== ''): ?>
                <?php
                    $clearParams = $runParam ? ['run' => $runParam] : [];
                ?>
                <a href="<?php echo e(route('enrichment.results', $clearParams)); ?>" class="inline-flex items-center px-3 py-2 text-sm text-gray-500 hover:text-gray-700">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <a href="<?php echo e($buildSortUrl('company')); ?>" class="inline-flex items-center gap-1 hover:text-gray-900">
                                Company <span class="text-gray-300"><?php echo e($buildSortIcon('company')); ?></span>
                            </a>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <a href="<?php echo e($buildSortUrl('contact')); ?>" class="inline-flex items-center gap-1 hover:text-gray-900">
                                Contact <span class="text-gray-300"><?php echo e($buildSortIcon('contact')); ?></span>
                            </a>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <a href="<?php echo e($buildSortUrl('role')); ?>" class="inline-flex items-center gap-1 hover:text-gray-900">
                                Role <span class="text-gray-300"><?php echo e($buildSortIcon('role')); ?></span>
                            </a>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Phone</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <a href="<?php echo e($buildSortUrl('score')); ?>" class="inline-flex items-center gap-1 hover:text-gray-900">
                                Score <span class="text-gray-300"><?php echo e($buildSortIcon('score')); ?></span>
                            </a>
                        </th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <a href="<?php echo e($buildSortUrl('status')); ?>" class="inline-flex items-center gap-1 hover:text-gray-900">
                                Status <span class="text-gray-300"><?php echo e($buildSortIcon('status')); ?></span>
                            </a>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php $__empty_1 = true; $__currentLoopData = $results; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $contactIndex => $contact): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr class="hover:bg-gray-50 transition-colors cursor-pointer"
                            onclick="document.getElementById('modal-<?php echo e($contactIndex); ?>').classList.remove('hidden')">
                            <td class="px-4 py-3 text-sm font-medium text-gray-900 whitespace-nowrap">
                                <?php echo e($contact['company_name']); ?>

                                <?php if(!empty($contact['regulated_industry'])): ?>
                                    <span class="ml-1.5 inline-flex items-center rounded-full bg-purple-50 px-1.5 py-0.5 text-[10px] font-medium text-purple-700">
                                        <?php echo e($contact['regulated_industry']); ?>

                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700 whitespace-nowrap"><?php echo e($contact['contact_name'] ?: '—'); ?></td>
                            <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">
                                <?php echo e($contact['contact_role'] ?: '—'); ?>

                                <?php if(!empty($contact['all_roles']) && count($contact['all_roles']) > 1): ?>
                                    <span class="ml-1 text-[10px] text-gray-400" title="Also: <?php echo e(implode(', ', array_keys($contact['all_roles']))); ?>">
                                        +<?php echo e(count($contact['all_roles']) - 1); ?>

                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap"><?php echo e($contact['contact_email'] ?: '—'); ?></td>
                            <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap"><?php echo e($contact['contact_phone'] ?: '—'); ?></td>
                            <td class="px-4 py-3 text-center whitespace-nowrap">
                                <?php $score = $contact['confidence_score']; ?>
                                <span class="inline-flex items-center justify-center w-10 h-6 rounded-full text-xs font-semibold
                                    <?php echo e($score >= 70 ? 'bg-emerald-100 text-emerald-800' : ($score >= 40 ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-600')); ?>">
                                    <?php echo e($score); ?>

                                </span>
                            </td>
                            <td class="px-4 py-3 text-center whitespace-nowrap">
                                <?php $status = $contact['verification_status']; ?>
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium
                                    <?php echo e($status === 'verified' ? 'bg-emerald-50 text-emerald-700' : ''); ?>

                                    <?php echo e($status === 'unverified' ? 'bg-amber-50 text-amber-700' : ''); ?>

                                    <?php echo e($status === 'conflicting' ? 'bg-red-50 text-red-700' : ''); ?>

                                    <?php echo e($status === 'not_found' ? 'bg-gray-100 text-gray-500' : ''); ?>">
                                    <?php if($status === 'verified'): ?>
                                        <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
                                    <?php endif; ?>
                                    <?php echo e($status); ?>

                                </span>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-400">
                                No results match your filters.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if(isset($pagination) && $pagination['total_pages'] > 1): ?>
            <div class="flex items-center justify-between border-t border-gray-200 bg-gray-50 px-4 py-3">
                <p class="text-xs text-gray-500">
                    Showing <?php echo e(($pagination['page'] - 1) * $pagination['per_page'] + 1); ?>–<?php echo e(min($pagination['page'] * $pagination['per_page'], $pagination['total'])); ?>

                    of <?php echo e($pagination['total']); ?> results
                </p>
                <div class="flex gap-1">
                    <?php for($pageNumber = 1; $pageNumber <= $pagination['total_pages']; $pageNumber++): ?>
                        <?php
                            $pageParams = array_merge($filters, ['page' => $pageNumber]);
                            if ($runParam) $pageParams['run'] = $runParam;
                            $pageUrl = route('enrichment.results', array_filter($pageParams, fn($v) => $v !== ''));
                        ?>
                        <a href="<?php echo e($pageUrl); ?>"
                           class="inline-flex items-center justify-center w-8 h-8 rounded text-xs font-medium transition-colors
                                  <?php echo e($pageNumber === $pagination['page'] ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-200'); ?>">
                            <?php echo e($pageNumber); ?>

                        </a>
                    <?php endfor; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php $__currentLoopData = $results; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $contactIndex => $contact): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <div id="modal-<?php echo e($contactIndex); ?>" class="hidden fixed inset-0 z-50 overflow-y-auto" onclick="if(event.target===this) this.classList.add('hidden')">
        <div class="flex min-h-full items-center justify-center p-4">
            <div class="relative w-full max-w-2xl rounded-xl bg-white shadow-2xl ring-1 ring-gray-900/5">
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900"><?php echo e($contact['company_name']); ?></h3>
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
                            <p class="mt-0.5 text-sm text-gray-900"><?php echo e($contact['contact_name'] ?: '—'); ?></p>
                        </div>
                        <div>
                            <p class="text-[10px] font-medium text-gray-400 uppercase tracking-wider">Role</p>
                            <p class="mt-0.5 text-sm text-gray-900"><?php echo e($contact['contact_role'] ?: '—'); ?></p>
                        </div>
                        <div>
                            <p class="text-[10px] font-medium text-gray-400 uppercase tracking-wider">Email</p>
                            <p class="mt-0.5 text-sm text-gray-900"><?php echo e($contact['contact_email'] ?: '—'); ?></p>
                        </div>
                        <div>
                            <p class="text-[10px] font-medium text-gray-400 uppercase tracking-wider">Phone</p>
                            <p class="mt-0.5 text-sm text-gray-900"><?php echo e($contact['contact_phone'] ?: '—'); ?></p>
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <?php $modalScore = $contact['confidence_score']; ?>
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center justify-center w-12 h-8 rounded-full text-sm font-bold
                                <?php echo e($modalScore >= 70 ? 'bg-emerald-100 text-emerald-800' : ($modalScore >= 40 ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-600')); ?>">
                                <?php echo e($modalScore); ?>

                            </span>
                            <span class="text-xs text-gray-500">confidence</span>
                        </div>

                        <?php $modalStatus = $contact['verification_status']; ?>
                        <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium
                            <?php echo e($modalStatus === 'verified' ? 'bg-emerald-50 text-emerald-700' : ''); ?>

                            <?php echo e($modalStatus === 'unverified' ? 'bg-amber-50 text-amber-700' : ''); ?>

                            <?php echo e($modalStatus === 'conflicting' ? 'bg-red-50 text-red-700' : ''); ?>

                            <?php echo e($modalStatus === 'not_found' ? 'bg-gray-100 text-gray-500' : ''); ?>">
                            <?php echo e($modalStatus); ?>

                        </span>

                        <?php if($contact['needs_human_review']): ?>
                            <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700">
                                needs review
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if(!empty($contact['all_roles']) && count($contact['all_roles']) > 1): ?>
                        <div>
                            <p class="text-[10px] font-medium text-gray-400 uppercase tracking-wider mb-1.5">All Roles Found</p>
                            <div class="flex flex-wrap gap-1.5">
                                <?php $__currentLoopData = $contact['all_roles']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $roleName => $providerName): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-1 text-xs">
                                        <span class="font-medium text-gray-700"><?php echo e($roleName); ?></span>
                                        <span class="text-gray-400"><?php echo e($providerName); ?></span>
                                    </span>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if(!empty($contact['provenance'])): ?>
                        <div>
                            <p class="text-[10px] font-medium text-gray-400 uppercase tracking-wider mb-1.5">Provenance</p>
                            <div class="space-y-1">
                                <?php $__currentLoopData = $contact['provenance']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $field => $provenanceData): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <div class="flex items-start gap-2 text-xs">
                                        <span class="font-medium text-gray-600 w-12 shrink-0"><?php echo e($field); ?></span>
                                        <span class="text-gray-500"><?php echo e($provenanceData['value']); ?></span>
                                        <span class="text-gray-300 ml-auto shrink-0">
                                            <?php echo e(implode(', ', array_column($provenanceData['sources'], 'provider'))); ?>

                                        </span>
                                    </div>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if(!empty($contact['explanation'])): ?>
                        <div>
                            <p class="text-[10px] font-medium text-gray-400 uppercase tracking-wider mb-1.5">Analysis</p>
                            <p class="text-sm text-gray-600 leading-relaxed"><?php echo e($contact['explanation']); ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="border-t border-gray-100 px-6 py-3 flex items-center justify-between">
                    <div>
                        <?php if(!empty($contact['manually_verified'])): ?>
                            <span class="inline-flex items-center gap-1 text-xs text-emerald-600">
                                <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
                                Manually verified <?php echo e($contact['verified_at'] ?? ''); ?>

                            </span>
                        <?php elseif($contact['verification_status'] !== 'verified' && $contact['verification_status'] !== 'not_found' && $runParam): ?>
                            <form method="POST" action="<?php echo e(route('enrichment.verify')); ?>" class="inline"
                                  onsubmit="return confirm('Mark this contact as verified?')">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="run_id" value="<?php echo e($runParam); ?>">
                                <input type="hidden" name="company_name" value="<?php echo e($contact['company_name']); ?>">
                                <button type="submit"
                                        class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-500 transition-colors">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                    </svg>
                                    Mark as Verified
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <button onclick="this.closest('[id^=modal-]').classList.add('hidden')"
                            class="rounded-md bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-200 transition-colors">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
<?php /**PATH C:\Users\marce\Documents\GitHub\hiring-challenge\resources\views/enrichment/results.blade.php ENDPATH**/ ?>