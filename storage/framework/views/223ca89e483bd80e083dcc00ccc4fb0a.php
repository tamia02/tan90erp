<?php

use App\Models\GateEntry;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Guard Entries</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Every gate entry logged so far.</p>

    <div class="flex flex-col gap-2">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $entries; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $entry): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between flex-wrap gap-2">
                    <span class="text-sm font-medium" style="color: var(--text-primary);"><?php echo e($entry->gate_no); ?> <span class="text-xs" style="color: var(--text-muted); font-weight: normal; margin-left: 4px;">&bull; Type: <?php echo e(ucfirst($entry->entry_type)); ?></span></span>
                    <span class="text-xs font-medium capitalize" style="color: var(--text-muted);"><?php echo e(str_replace('_', ' ', $entry->status)); ?></span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">
                    <?php echo e($entry->vendor_name ?? $entry->vehicle_number); ?> · <?php echo e($entry->material ?? 'No material set'); ?> · <?php echo e($entry->invoice_qty ?? '—'); ?> qty
                </div>
                <div class="text-xs mt-2" style="color: var(--text-muted);">
                    <?php echo e($entry->vehicle_number); ?> · <?php echo e($entry->driver_name); ?> · <?php echo e($entry->created_at->format('d M Y, H:i')); ?>

                </div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No gate entries yet.</div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\guard\entries.blade.php ENDPATH**/ ?>