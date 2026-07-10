<?php

use App\Support\NotificationCenter;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="max-w-2xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Notifications</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Alerts relevant to your role, computed from live data.</p>

    <div class="flex flex-col gap-2">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $notices; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $n): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border); border-left: 3px solid <?php echo e($n['tone'] === 'critical' ? 'var(--status-critical)' : ($n['tone'] === 'warning' ? 'var(--status-warning)' : 'var(--status-good)')); ?>;">
                <div class="text-sm font-medium" style="color: var(--text-primary);"><?php echo e($n['title']); ?></div>
                <div class="text-xs mt-0.5" style="color: var(--text-secondary);"><?php echo e($n['detail']); ?></div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">Nothing needs your attention right now.</div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\shared\notifications.blade.php ENDPATH**/ ?>