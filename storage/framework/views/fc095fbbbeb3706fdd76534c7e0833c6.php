<?php

use App\Models\GateEntry;
use App\Services\GrnPostingService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">GRN Check</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Post the QC-checked split to stock and close the gate entry.</p>

    <div class="flex flex-col gap-3">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $queue; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $g): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <?php $qc = $g->qcResult; ?>
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="text-sm font-medium" style="color: var(--text-primary);"><?php echo e($g->gate_no); ?> · <?php echo e($qc?->sku); ?></div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                            Accepted <?php echo e($qc?->accepted_qty); ?> · Hold <?php echo e($qc?->qc_hold_qty); ?> · Defective <?php echo e($qc?->defective_qty); ?> · Rejected <?php echo e($qc?->rejected_qty); ?>

                        </div>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($qc?->qc_reasons): ?>
                            <div class="text-xs mt-1" style="color: var(--text-secondary);">QC notes: <?php echo e($qc->qc_reasons); ?></div>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($posting !== $g->id): ?>
                        <button wire:click="openPost(<?php echo e($g->id); ?>)" class="rounded-lg px-3 py-1.5 text-sm font-medium text-white shrink-0" style="background: var(--brand);">Post GRN</button>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>

                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($posting === $g->id): ?>
                    <div class="mt-4">
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Suggested bin</span>
                            <input wire:model="suggestedBin" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="BHW-PCM-A1" />
                        </label>
                        <button wire:click="post" class="mt-4 rounded-lg px-4 py-2 text-sm font-medium text-white" style="background: var(--brand);">Post GRN &amp; update stock</button>
                    </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">Nothing waiting for GRN posting.</div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\grn\check.blade.php ENDPATH**/ ?>