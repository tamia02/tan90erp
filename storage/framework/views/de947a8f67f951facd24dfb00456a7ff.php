<?php

use App\Models\FinanceRecord;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Reports</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Final payable totals across every closed gate entry.</p>

    <div class="grid grid-cols-2 gap-3 mb-6">
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Total Invoice Value</div>
            <div class="text-xl font-semibold mt-1" style="color: var(--text-primary);">₹<?php echo e(number_format($totalInvoiceValue, 2)); ?></div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Total Accepted Value</div>
            <div class="text-xl font-semibold mt-1" style="color: var(--text-primary);">₹<?php echo e(number_format($totalAcceptedValue, 2)); ?></div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Total Deductions</div>
            <div class="text-xl font-semibold mt-1" style="color: var(--status-critical);">₹<?php echo e(number_format($totalDeductions, 2)); ?></div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Final Payable</div>
            <div class="text-xl font-semibold mt-1" style="color: var(--status-good);">₹<?php echo e(number_format($totalFinalPayable, 2)); ?></div>
        </div>
    </div>

    <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Records by month</h2>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $byMonth; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $month => $count): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div class="flex justify-between text-sm py-1.5" style="color: var(--text-secondary);">
                <span><?php echo e($month); ?></span>
                <span style="color: var(--text-primary);"><?php echo e($count); ?></span>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\finance\reports.blade.php ENDPATH**/ ?>