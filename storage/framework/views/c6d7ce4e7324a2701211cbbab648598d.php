<?php

use App\Models\GateEntry;
use App\Models\UnloadingRecord;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Unloading Desk</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Start and complete unloading for cleared vehicles.</p>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Ready to start</h2>
    <div class="flex flex-col gap-2 mb-6">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $toStart; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $g): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <div class="rounded-lg border p-4 flex items-center justify-between gap-3" style="background: var(--surface-3); border-color: var(--border);">
                <div>
                    <div class="text-sm font-medium" style="color: var(--text-primary);"><?php echo e($g->gate_no); ?></div>
                    <div class="text-xs mt-0.5" style="color: var(--text-muted);"><?php echo e($g->vendor_name ?? $g->vehicle_number); ?> · <?php echo e($g->material); ?></div>
                </div>
                <button wire:click="startUnloading(<?php echo e($g->id); ?>)" class="rounded-lg px-3 py-1.5 text-sm font-medium text-white" style="background: var(--brand);">Start unloading</button>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <p class="text-sm py-2" style="color: var(--text-muted);">Nothing waiting to start.</p>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">In progress</h2>
    <div class="flex flex-col gap-2">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $inProgress; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $g): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3 mb-2">
                    <div>
                        <div class="text-sm font-medium" style="color: var(--text-primary);"><?php echo e($g->gate_no); ?></div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);"><?php echo e($g->vendor_name ?? $g->vehicle_number); ?> · <?php echo e($g->material); ?></div>
                    </div>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($completing !== $g->id): ?>
                        <button wire:click="$set('completing', <?php echo e($g->id); ?>)" class="rounded-lg px-3 py-1.5 text-sm font-medium border" style="border-color: var(--border); color: var(--text-primary);">Complete</button>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($completing === $g->id): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Box count</span>
                            <input wire:model="boxCount" type="number" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Staging area</span>
                            <select wire:model="stagingArea" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $stagingAreas; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $s): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?> <option value="<?php echo e($s); ?>"><?php echo e($s); ?></option> <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </select>
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">POD / LR ref</span>
                            <input wire:model="podLrRef" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="LR-88213" />
                        </label>
                        <button wire:click="completeUnloading(<?php echo e($g->id); ?>)" class="sm:col-span-3 rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Complete &amp; send to QC Check</button>
                    </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <p class="text-sm py-2" style="color: var(--text-muted);">Nothing in progress.</p>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>

    <h2 class="font-semibold text-sm mb-2 mt-6" style="color: var(--text-primary);">History</h2>
    <div class="flex flex-col gap-2">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $history; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-medium" style="color: var(--text-primary);"><?php echo e($r->gateEntry?->gate_no); ?></div>
                    <span class="text-xs" style="color: var(--text-muted);"><?php echo e($r->completed_at ? 'Completed' : 'In progress'); ?></span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);"><?php echo e($r->gateEntry?->vendor_name); ?> · <?php echo e($r->box_count); ?> boxes · <?php echo e($r->staging_area); ?></div>
                <div class="text-xs mt-1" style="color: var(--text-muted);">Started <?php echo e($r->started_at->format('d M, H:i')); ?><?php echo e($r->completed_at ? ' · Completed '.$r->completed_at->format('d M, H:i') : ''); ?></div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No unloading records yet.</div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\unloading\desk.blade.php ENDPATH**/ ?>