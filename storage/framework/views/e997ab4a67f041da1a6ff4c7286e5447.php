<?php

use App\Models\FinanceRecord;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Finance Review</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Every payable, deductions and vendor closure status.</p>

    <div class="flex flex-col gap-3">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $records; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <div>
                        <div class="text-sm font-medium" style="color: var(--text-primary);"><?php echo e($r->gateEntry?->gate_no); ?> · <?php echo e($r->vendor_name); ?></div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">Invoice <?php echo e($r->invoice_number ?? '—'); ?> · Rate ₹<?php echo e($r->rate_per_unit); ?>/unit</div>
                    </div>
                    <span class="text-xs font-medium capitalize px-2 py-0.5 rounded" style="background: var(--surface-2); color: <?php echo e($r->vendor_status === 'cleared' ? 'var(--status-good)' : ($r->vendor_status === 'hold' ? 'var(--status-critical)' : 'var(--status-warning)')); ?>;"><?php echo e($r->vendor_status); ?></span>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-3 text-xs">
                    <div><div style="color: var(--text-muted);">Invoice Value</div><div class="font-medium" style="color: var(--text-primary);">₹<?php echo e(number_format($r->invoice_value, 2)); ?></div></div>
                    <div><div style="color: var(--text-muted);">Accepted Value</div><div class="font-medium" style="color: var(--text-primary);">₹<?php echo e(number_format($r->accepted_value, 2)); ?></div></div>
                    <div><div style="color: var(--text-muted);">Deductions</div><div class="font-medium" style="color: var(--status-critical);">₹<?php echo e(number_format($r->deduction_defective + $r->deduction_rejected + $r->deduction_missing, 2)); ?></div></div>
                    <div><div style="color: var(--text-muted);">Final Payable</div><div class="font-medium" style="color: var(--text-primary);">₹<?php echo e(number_format($r->final_payable, 2)); ?></div></div>
                </div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($r->notes): ?>
                    <div class="text-xs mt-2" style="color: var(--text-secondary);"><?php echo e($r->notes); ?></div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($editing === $r->id): ?>
                    <div class="mt-3">
                        <textarea wire:model="notes" rows="2" class="w-full rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Notes (optional)"></textarea>
                    </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                <div class="flex gap-2 mt-3">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($editing !== $r->id): ?>
                        <button wire:click="$set('editing', <?php echo e($r->id); ?>)" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--border); color: var(--text-primary);">Add note</button>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <button wire:click="setStatus(<?php echo e($r->id); ?>, 'cleared')" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--status-good); color: var(--status-good);">Clear</button>
                    <button wire:click="setStatus(<?php echo e($r->id); ?>, 'hold')" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--status-critical); color: var(--status-critical);">Hold</button>
                </div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No finance records yet.</div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\finance\review.blade.php ENDPATH**/ ?>