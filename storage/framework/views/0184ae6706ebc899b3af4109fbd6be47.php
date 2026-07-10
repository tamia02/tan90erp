<?php

use App\Models\LedgerEntry;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Shelf &amp; Bin</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">What's currently sitting in each bin.</p>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $byBin; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $bin => $skus): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="text-sm font-medium mb-2" style="color: var(--text-primary);"><?php echo e($bin); ?></div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $skus; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $sku => $qty): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div class="text-xs py-1 flex justify-between" style="color: var(--text-secondary);">
                        <span><?php echo e($sku); ?></span>
                        <span style="color: var(--text-primary);"><?php echo e($qty); ?></span>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <div class="text-center text-sm py-10 sm:col-span-2" style="color: var(--text-muted);">No bins in use yet.</div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\grn\shelf-bin.blade.php ENDPATH**/ ?>