<div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                    <th class="px-4 py-2.5 font-medium">SKU</th>
                    <th class="px-4 py-2.5 font-medium">Bin</th>
                    <th class="px-4 py-2.5 font-medium">Bucket</th>
                    <th class="px-4 py-2.5 font-medium">Qty</th>
                    <th class="px-4 py-2.5 font-medium">Timestamp</th>
                </tr>
            </thead>
            <tbody>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $entries; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $e): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);"><?php echo e($e->sku); ?></td>
                        <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);"><?php echo e($e->bin); ?></td>
                        <td class="px-4 py-2.5 text-xs capitalize" style="color: <?php echo e($e->bucket === 'available' ? 'var(--status-good)' : (in_array($e->bucket, ['defective', 'rejected']) ? 'var(--status-critical)' : 'var(--status-warning)')); ?>;"><?php echo e($e->bucket); ?></td>
                        <td class="px-4 py-2.5" style="color: var(--text-primary);"><?php echo e($e->qty); ?></td>
                        <td class="px-4 py-2.5 text-xs" style="color: var(--text-muted);"><?php echo e($e->created_at->format('d M Y, H:i')); ?></td>
                    </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <tr><td colspan="5" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No stock ledger entries yet.</td></tr>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\partials\stock-ledger-table.blade.php ENDPATH**/ ?>