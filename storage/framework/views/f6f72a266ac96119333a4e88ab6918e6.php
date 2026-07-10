<?php

use App\Models\GrnRecord;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">GRN Register</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Every GRN posted so far.</p>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="px-4 py-2.5 font-medium">Gate No</th>
                        <th class="px-4 py-2.5 font-medium">SKU</th>
                        <th class="px-4 py-2.5 font-medium">Accepted</th>
                        <th class="px-4 py-2.5 font-medium">Defective</th>
                        <th class="px-4 py-2.5 font-medium">Rejected</th>
                        <th class="px-4 py-2.5 font-medium">Bin</th>
                        <th class="px-4 py-2.5 font-medium">Posted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $records; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);"><?php echo e($r->gateEntry?->gate_no); ?></td>
                            <td class="px-4 py-2.5" style="color: var(--text-secondary);"><?php echo e($r->sku); ?></td>
                            <td class="px-4 py-2.5"><?php echo e($r->accepted_qty); ?></td>
                            <td class="px-4 py-2.5"><?php echo e($r->defective_qty); ?></td>
                            <td class="px-4 py-2.5"><?php echo e($r->rejected_qty); ?></td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);"><?php echo e($r->suggested_bin); ?></td>
                            <td class="px-4 py-2.5"><?php echo e($r->posted ? 'Yes' : 'No'); ?></td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr><td colspan="7" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No GRNs posted yet.</td></tr>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\grn\register.blade.php ENDPATH**/ ?>