<?php

use App\Livewire\Actions\Logout;
use App\Support\RoleNavigation;
use Livewire\Volt\Component;

?>

<aside class="w-64 shrink-0 border-r flex flex-col" style="background: var(--surface-1); border-color: var(--border);">
    <div class="flex items-center gap-2.5 px-5 py-5 border-b" style="border-color: var(--border);">
        <div class="w-9 h-9 rounded-lg grid place-items-center font-bold text-sm text-white" style="background: var(--brand);">
            T90
        </div>
        <span class="font-semibold tracking-wide" style="color: var(--text-primary);">Tan90 ERP</span>
    </div>

    <nav class="flex-1 overflow-y-auto py-3 px-2.5 space-y-0.5">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $navItems; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <a
                href="<?php echo e(route($item['route'])); ?>"
                wire:navigate
                class="<?php echo \Illuminate\Support\Arr::toCssClasses([
                    'flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm font-medium transition-colors',
                ]); ?>"
                style="<?php echo e(request()->routeIs($item['route']) ? 'background: var(--brand-bg); color: var(--brand);' : 'color: var(--text-secondary);'); ?>"
            >
                <?php echo e($item['label']); ?>

            </a>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </nav>

    <div class="px-3 py-4 border-t" style="border-color: var(--border);">
        <div class="px-2 mb-2">
            <div class="text-sm font-medium truncate" style="color: var(--text-primary);"><?php echo e(auth()->user()->name); ?></div>
            <div class="text-xs truncate" style="color: var(--text-muted);"><?php echo e(auth()->user()->role->label()); ?></div>
        </div>
        <button
            wire:click="logout"
            class="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors"
            style="color: var(--status-critical);"
        >
            Log out
        </button>
    </div>
</aside><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire/layout/navigation.blade.php ENDPATH**/ ?>