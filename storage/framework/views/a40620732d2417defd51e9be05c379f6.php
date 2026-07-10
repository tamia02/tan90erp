<?php

use App\Enums\Role;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="max-w-2xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Help &amp; Support</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Answers for the <?php echo e(auth()->user()->role->label()); ?> module.</p>

    <div class="flex flex-col gap-2 mb-6">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $faqs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $faq): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="text-sm font-medium" style="color: var(--text-primary);"><?php echo e($faq['q']); ?></div>
                <div class="text-sm mt-1" style="color: var(--text-secondary);"><?php echo e($faq['a']); ?></div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>

    <div class="rounded-lg border p-4" style="background: var(--surface-2); border-color: var(--border);">
        <div class="text-sm font-medium" style="color: var(--text-primary);">Still need help?</div>
        <div class="text-sm mt-1" style="color: var(--text-secondary);">Contact support at +91 90000 00000.</div>
    </div>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\shared\help-support.blade.php ENDPATH**/ ?>