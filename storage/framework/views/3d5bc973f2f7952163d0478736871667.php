<?php

use App\Models\LedgerEntry;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Stock Balance</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Current quantity per SKU, aggregated across every ledger posting.</p>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="px-4 py-2.5 font-medium">SKU</th>
                        <th class="px-4 py-2.5 font-medium">Bin</th>
                        <th class="px-4 py-2.5 font-medium">Available</th>
                        <th class="px-4 py-2.5 font-medium">QC Hold</th>
                        <th class="px-4 py-2.5 font-medium">Defective</th>
                        <th class="px-4 py-2.5 font-medium">Rejected</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $balance; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $b): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);"><?php echo e($b['sku']); ?></td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);"><?php echo e($b['bin']); ?></td>
                            <td class="px-4 py-2.5" style="color: var(--status-good);"><?php echo e($b['available']); ?></td>
                            <td class="px-4 py-2.5" style="color: var(--status-warning);"><?php echo e($b['qcHold']); ?></td>
                            <td class="px-4 py-2.5" style="color: var(--status-critical);"><?php echo e($b['defective']); ?></td>
                            <td class="px-4 py-2.5" style="color: var(--status-critical);"><?php echo e($b['rejected']); ?></td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr><td colspan="6" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No stock posted yet.</td></tr>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\grn\stock-balance.blade.php ENDPATH**/ ?>