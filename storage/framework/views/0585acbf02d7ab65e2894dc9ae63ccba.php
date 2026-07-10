<?php

use App\Models\GateEntry;
use App\Services\QcService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">QC Queue</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Split each delivery into accepted / hold / defective / rejected before it can go to GRN Check.</p>

    <div class="flex flex-col gap-3">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $queue; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $g): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="text-sm font-medium" style="color: var(--text-primary);"><?php echo e($g->gate_no); ?></div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);"><?php echo e($g->material); ?> · Invoice qty <?php echo e($g->invoice_qty); ?></div>
                    </div>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($checking !== $g->id): ?>
                        <button wire:click="openCheck(<?php echo e($g->id); ?>)" class="rounded-lg px-3 py-1.5 text-sm font-medium text-white" style="background: var(--brand);">QC Check</button>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>

                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($checking === $g->id): ?>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4">
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Accepted</span>
                            <input wire:model="accepted" type="number" min="0" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">QC Hold</span>
                            <input wire:model="qcHold" type="number" min="0" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Defective</span>
                            <input wire:model="defective" type="number" min="0" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Rejected</span>
                            <input wire:model="rejected" type="number" min="0" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm sm:col-span-4">
                            <span class="font-medium" style="color: var(--text-primary);">QC reasons (if any hold/defective/rejected)</span>
                            <textarea wire:model="qcReasons" rows="2" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);"></textarea>
                        </label>
                        <button wire:click="submitCheck" class="sm:col-span-4 rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Submit QC Check &amp; send to GRN Check</button>
                    </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">Nothing waiting for QC.</div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\qc\queue.blade.php ENDPATH**/ ?>