<?php

use App\Models\ValidationIssue;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Validation Issues</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Every issue raised at the gate, across all vendors and POs.</p>

    <div class="flex flex-col gap-2">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $issues; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $issue): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center gap-2 mb-2 flex-wrap">
                    <span class="text-xs font-medium px-2 py-0.5 rounded" style="background: <?php echo e($issue->severity === 'hardFail' ? 'var(--status-critical-bg)' : ($issue->severity === 'redFlag' ? 'var(--status-warning-bg)' : 'var(--surface-2)')); ?>; color: <?php echo e($issue->severity === 'hardFail' ? 'var(--status-critical)' : ($issue->severity === 'redFlag' ? 'var(--status-warning)' : 'var(--text-muted)')); ?>;"><?php echo e($issue->severity); ?></span>
                    <span class="text-xs font-medium px-2 py-0.5 rounded" style="background: var(--surface-2); color: var(--text-secondary);"><?php echo e($issue->status); ?></span>
                </div>
                <h3 class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e($issue->title); ?></h3>
                <p class="text-sm mt-0.5" style="color: var(--text-secondary);"><?php echo e($issue->description); ?></p>
                <p class="text-xs mt-2" style="color: var(--text-muted);">
                    <?php echo e($issue->gateEntry?->po_number ? $issue->gateEntry->po_number.' · ' : ''); ?><?php echo e($issue->gateEntry?->vendor_name ?? 'Unknown vendor'); ?> · Raised <?php echo e($issue->created_at->format('d M Y, H:i')); ?>

                </p>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($issue->status === 'open'): ?>
                    <div class="flex gap-2 mt-3">
                        <button wire:click="updateStatus(<?php echo e($issue->id); ?>, 'approved')" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--border); color: var(--text-primary);">Approve</button>
                        <button wire:click="updateStatus(<?php echo e($issue->id); ?>, 'resolved')" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--status-good); color: var(--status-good);">Resolve</button>
                        <button wire:click="updateStatus(<?php echo e($issue->id); ?>, 'escalated')" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--status-critical); color: var(--status-critical);">Escalate</button>
                    </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No issues raised.</div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\validation-issues.blade.php ENDPATH**/ ?>