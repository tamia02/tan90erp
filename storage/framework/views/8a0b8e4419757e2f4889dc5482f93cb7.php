<?php

use App\Models\LedgerEntry;
use App\Models\QcResult;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">History</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Every QC Check recorded so far.</p>

    <div class="flex flex-col gap-2 mb-6">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $results; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-medium" style="color: var(--text-primary);"><?php echo e($r->gateEntry?->gate_no); ?></div>
                    <span class="text-xs" style="color: var(--text-muted);"><?php echo e($r->created_at->format('d M, H:i')); ?></span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);"><?php echo e($r->sku); ?> · accepted <?php echo e($r->accepted_qty); ?>, hold <?php echo e($r->qc_hold_qty); ?>, defective <?php echo e($r->defective_qty); ?>, rejected <?php echo e($r->rejected_qty); ?></div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($r->qc_reasons): ?>
                    <div class="text-xs mt-1" style="color: var(--text-muted);"><?php echo e($r->qc_reasons); ?></div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No QC checks recorded yet.</div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Stock Ledger</h2>
    <p class="text-xs mb-3" style="color: var(--text-secondary);">Read-only — every posting from GRN Check.</p>
    <?php echo $__env->make('partials.stock-ledger-table', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\qc\history.blade.php ENDPATH**/ ?>