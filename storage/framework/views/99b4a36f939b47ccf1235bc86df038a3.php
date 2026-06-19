

<?php $__env->startSection('title', 'Calibration Result'); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <div>
        <h1 class="text-xl font-semibold text-gray-900">Calibration Result</h1>
        <p class="mt-1 text-sm text-gray-500">Weight calibration completed based on your validated labels.</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="rounded-lg border border-gray-200 bg-white p-6">
            <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wider mb-4">Previous Weights</h3>
            <?php
                $weightLabels = [
                    'agreement' => 'Agreement (source consensus)',
                    'authority' => 'Authority (source reliability)',
                    'completeness' => 'Completeness (data fields)',
                    'recency' => 'Recency (data freshness)',
                ];
            ?>
            <dl class="space-y-2">
                <?php $__currentLoopData = $previousWeights; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $dimension => $weight): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div class="flex justify-between items-center">
                        <dt class="text-sm text-gray-600"><?php echo e($weightLabels[$dimension] ?? ucfirst($dimension)); ?></dt>
                        <dd class="text-sm font-mono font-medium text-gray-400"><?php echo e(number_format($weight, 2)); ?></dd>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-1.5">
                        <div class="bg-gray-300 h-1.5 rounded-full" style="width: <?php echo e($weight * 100); ?>%"></div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </dl>
        </div>

        <div class="rounded-lg border-2 <?php echo e($applied ? 'border-emerald-200 bg-emerald-50/30' : 'border-gray-200 bg-white'); ?> p-6">
            <div class="flex items-center gap-2 mb-4">
                <h3 class="text-sm font-medium <?php echo e($applied ? 'text-emerald-700' : 'text-gray-500'); ?> uppercase tracking-wider">
                    New Weights
                </h3>
                <?php if($applied): ?>
                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">
                        APPLIED
                    </span>
                <?php endif; ?>
            </div>
            <dl class="space-y-2">
                <?php $__currentLoopData = $newWeights; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $dimension => $weight): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div class="flex justify-between items-center">
                        <dt class="text-sm text-gray-600"><?php echo e($weightLabels[$dimension] ?? ucfirst($dimension)); ?></dt>
                        <dd class="text-sm font-mono font-semibold text-gray-900"><?php echo e(number_format($weight, 2)); ?></dd>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-1.5">
                        <div class="<?php echo e($applied ? 'bg-emerald-500' : 'bg-indigo-500'); ?> h-1.5 rounded-full" style="width: <?php echo e($weight * 100); ?>%"></div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </dl>
        </div>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <div class="grid grid-cols-3 gap-6 text-center">
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Samples Used</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900"><?php echo e($sampleCount); ?></p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Labeled Correct</p>
                <p class="mt-1 text-2xl font-semibold text-emerald-700"><?php echo e($correctCount); ?></p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Labeled Incorrect</p>
                <p class="mt-1 text-2xl font-semibold text-red-600"><?php echo e($sampleCount - $correctCount); ?></p>
            </div>
        </div>
    </div>

    <?php if(!$applied): ?>
        <div class="rounded-md bg-amber-50 border border-amber-200 p-4">
            <p class="text-sm text-amber-700">
                Weights were not updated because the new weights did not improve accuracy over the current ones.
                Consider adding more labeled data or reviewing your labels.
            </p>
        </div>
    <?php endif; ?>

    <div class="flex gap-3">
        <a href="<?php echo e(route('training.index')); ?>"
           class="inline-flex items-center gap-2 rounded-md bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-gray-800 transition-colors">
            Train Again
        </a>
        <a href="<?php echo e(route('enrichment.index')); ?>"
           class="inline-flex items-center gap-2 rounded-md bg-white border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 transition-colors">
            Run Enrichment
        </a>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\marce\Documents\GitHub\hiring-challenge\resources\views/training/calibration-result.blade.php ENDPATH**/ ?>