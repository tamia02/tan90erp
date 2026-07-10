<?php

use App\Models\FinanceRecord;
use App\Models\GateEntry;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="max-w-5xl mx-auto">
    <div class="mb-5">
        <h1 class="text-xl sm:text-2xl font-semibold" style="color: var(--text-primary);">Command Center</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Every gate entry's position across the full Inward → GRN pipeline.</p>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $stages; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $s): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="text-xs" style="color: var(--text-muted);"><?php echo e($s['label']); ?></div>
                <div class="text-2xl font-semibold mt-1" style="color: <?php echo e($s['tone'] === 'good' ? 'var(--status-good)' : 'var(--status-warning)'); ?>;"><?php echo e($s['count']); ?></div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>

    <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs" style="color: var(--text-muted);">Pending Vendor Payable (all vendors)</div>
        <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">₹<?php echo e(number_format($pendingPayable, 2)); ?></div>
    </div>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\admin\command-center.blade.php ENDPATH**/ ?>