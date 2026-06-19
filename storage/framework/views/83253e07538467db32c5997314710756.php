<div class="space-y-4">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Total Companies</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900"><?php echo e($totalCompanies); ?></p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium text-indigo-600 uppercase tracking-wider">Training Sample (<?php echo e($samplePercentage ?? 20); ?>%)</p>
            <p class="mt-1 text-2xl font-semibold text-indigo-700"><?php echo e(count($trainingData)); ?></p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 sm:col-span-2">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Current Weights</p>
            <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600">
                <span>Agreement (source consensus): <strong><?php echo e($currentWeights['agreement']); ?></strong></span>
                <span>Authority (source reliability): <strong><?php echo e($currentWeights['authority']); ?></strong></span>
                <span>Completeness (data fields): <strong><?php echo e($currentWeights['completeness']); ?></strong></span>
                <span>Recency (data freshness): <strong><?php echo e($currentWeights['recency']); ?></strong></span>
            </div>
        </div>
    </div>

    <form method="POST" action="<?php echo e(route('training.calibrate')); ?>">
        <?php echo csrf_field(); ?>

        <div class="rounded-lg border border-gray-200 bg-white overflow-hidden">
            <div class="border-b border-gray-200 bg-gray-50 px-4 py-3">
                <p class="text-sm font-medium text-gray-700">
                    Review each contact and mark whether the enrichment result is correct. Click a row to see details.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr class="bg-gray-50/50">
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact Found</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Phone</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Score</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider w-32">Correct?</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php $__currentLoopData = $trainingData; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $index => $contact): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr class="hover:bg-gray-50 transition-colors cursor-pointer"
                                onclick="if(!event.target.closest('label')&&!event.target.closest('input')) document.getElementById('train-modal-<?php echo e($index); ?>').classList.remove('hidden')">
                                <td class="px-4 py-3 text-sm font-medium text-gray-900 whitespace-nowrap">
                                    <?php echo e($contact['company_name']); ?>

                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700 whitespace-nowrap">
                                    <?php if($contact['contact_name']): ?>
                                        <?php echo e($contact['contact_name']); ?>

                                        <?php if($contact['contact_role']): ?>
                                            <span class="text-gray-400">(<?php echo e($contact['contact_role']); ?>)</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-gray-400">—</span>
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
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                        <?php echo e($status === 'verified' ? 'bg-emerald-50 text-emerald-700' : ''); ?>

                                        <?php echo e($status === 'unverified' ? 'bg-amber-50 text-amber-700' : ''); ?>

                                        <?php echo e($status === 'conflicting' ? 'bg-red-50 text-red-700' : ''); ?>

                                        <?php echo e($status === 'not_found' ? 'bg-gray-100 text-gray-500' : ''); ?>">
                                        <?php echo e($status); ?>

                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center whitespace-nowrap" onclick="event.stopPropagation()">
                                    <input type="hidden"
                                           name="samples[<?php echo e($index); ?>][agreement]"
                                           value="<?php echo e($contact['component_scores']['agreement'] ?? 0); ?>">
                                    <input type="hidden"
                                           name="samples[<?php echo e($index); ?>][authority]"
                                           value="<?php echo e($contact['component_scores']['authority'] ?? 0); ?>">
                                    <input type="hidden"
                                           name="samples[<?php echo e($index); ?>][completeness]"
                                           value="<?php echo e($contact['component_scores']['completeness'] ?? 0); ?>">
                                    <input type="hidden"
                                           name="samples[<?php echo e($index); ?>][recency]"
                                           value="<?php echo e($contact['component_scores']['recency'] ?? 0); ?>">

                                    <div class="inline-flex rounded-md shadow-sm">
                                        <label class="relative">
                                            <input type="radio" name="samples[<?php echo e($index); ?>][is_correct]" value="1"
                                                   class="peer sr-only">
                                            <span class="inline-flex items-center px-3 py-1.5 rounded-l-md border border-gray-300 text-xs font-medium cursor-pointer
                                                         peer-checked:bg-emerald-50 peer-checked:text-emerald-700 peer-checked:border-emerald-300 peer-checked:z-10
                                                         bg-white text-gray-500 hover:bg-gray-50 transition-colors">
                                                Yes
                                            </span>
                                        </label>
                                        <label class="relative -ml-px">
                                            <input type="radio" name="samples[<?php echo e($index); ?>][is_correct]" value="0"
                                                   class="peer sr-only" checked>
                                            <span class="inline-flex items-center px-3 py-1.5 rounded-r-md border border-gray-300 text-xs font-medium cursor-pointer
                                                         peer-checked:bg-red-50 peer-checked:text-red-700 peer-checked:border-red-300 peer-checked:z-10
                                                         bg-white text-gray-500 hover:bg-gray-50 transition-colors">
                                                No
                                            </span>
                                        </label>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="flex items-center justify-between mt-4">
            <a href="<?php echo e(route('training.index')); ?>" class="text-sm text-gray-500 hover:text-gray-700">
                Start over
            </a>
            <button type="submit"
                    class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 transition-colors">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                </svg>
                Validate &amp; Calibrate Weights
            </button>
        </div>
    </form>
</div>

<?php $__currentLoopData = $trainingData; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $index => $contact): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <div id="train-modal-<?php echo e($index); ?>" class="hidden fixed inset-0 z-50 overflow-y-auto" onclick="if(event.target===this) this.classList.add('hidden')">
        <div class="flex min-h-full items-center justify-center p-4">
            <div class="relative w-full max-w-2xl rounded-xl bg-white shadow-2xl ring-1 ring-gray-900/5">
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900"><?php echo e($contact['company_name']); ?></h3>
                        <p class="mt-0.5 text-xs text-gray-400">Training Sample Details</p>
                    </div>
                    <button onclick="this.closest('[id^=train-modal-]').classList.add('hidden')"
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

                    <?php if(!empty($contact['component_scores'])): ?>
                        <div>
                            <p class="text-[10px] font-medium text-gray-400 uppercase tracking-wider mb-2">Score Breakdown</p>
                            <div class="grid grid-cols-2 gap-3">
                                <?php
                                    $componentLabels = [
                                        'agreement' => 'Agreement (source consensus)',
                                        'authority' => 'Authority (source reliability)',
                                        'completeness' => 'Completeness (data fields)',
                                        'recency' => 'Recency (data freshness)',
                                    ];
                                ?>
                                <?php $__currentLoopData = ['agreement', 'authority', 'completeness', 'recency']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $component): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <?php $componentValue = $contact['component_scores'][$component] ?? 0; ?>
                                    <div>
                                        <div class="flex justify-between text-xs mb-0.5">
                                            <span class="text-gray-600"><?php echo e($componentLabels[$component]); ?></span>
                                            <span class="font-medium text-gray-900"><?php echo e(number_format($componentValue, 2)); ?></span>
                                        </div>
                                        <div class="w-full bg-gray-100 rounded-full h-1.5">
                                            <div class="bg-indigo-500 h-1.5 rounded-full" style="width: <?php echo e($componentValue * 100); ?>%"></div>
                                        </div>
                                    </div>
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

                <div class="border-t border-gray-100 px-6 py-3 flex justify-end">
                    <button onclick="this.closest('[id^=train-modal-]').classList.add('hidden')"
                            class="rounded-md bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-200 transition-colors">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
<?php /**PATH C:\Users\marce\Documents\GitHub\hiring-challenge\resources\views/training/validation.blade.php ENDPATH**/ ?>