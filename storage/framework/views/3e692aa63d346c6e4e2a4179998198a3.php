<?php

use App\Enums\Role;
use App\Models\AuditLogEntry;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="space-y-5">
    <section class="rounded-2xl border overflow-hidden" style="background: linear-gradient(135deg, var(--surface-3), var(--surface-2)); border-color: var(--border);">
        <div class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-0">
            <div class="p-5 sm:p-6">
                <div class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide" style="background: <?php echo e($profile['accent']); ?>22; color: <?php echo e($profile['accent']); ?>;">
                    <?php echo e($role->moduleName()); ?>

                </div>
                <h1 class="text-2xl sm:text-3xl font-bold mt-4" style="color: var(--text-primary);"><?php echo e($profile['title']); ?></h1>
                <p class="text-sm sm:text-base mt-2 max-w-3xl" style="color: var(--text-secondary);"><?php echo e($profile['subtitle']); ?></p>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-5">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $stats; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as [$label, $value]): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <div class="rounded-xl border p-4" style="background: rgba(255,255,255,.04); border-color: var(--border);">
                            <div class="text-2xl font-bold" style="color: var(--text-primary);"><?php echo e($value); ?></div>
                            <div class="text-xs mt-1" style="color: var(--text-muted);"><?php echo e($label); ?></div>
                        </div>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </div>

            <div class="relative min-h-56 border-t lg:border-t-0 lg:border-l" style="border-color: var(--border);">
                <img src="<?php echo e(asset('tan90/evidence/'.$profile['image'])); ?>" alt="Document evidence preview" class="absolute inset-0 h-full w-full object-cover opacity-80">
                <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(2,6,23,.12), rgba(2,6,23,.82));"></div>
                <div class="absolute left-4 right-4 bottom-4 rounded-xl border p-3" style="background: rgba(2,6,23,.78); border-color: rgba(255,255,255,.16);">
                    <?php ($loc = $locations[$profile['locationKey']]); ?>
                    <div class="text-sm font-semibold text-white"><?php echo e($loc['name']); ?></div>
                    <div class="text-xs mt-1 text-slate-300"><?php echo e($loc['code']); ?> - <?php echo e($loc['owner']); ?> - <?php echo e($loc['phone']); ?></div>
                    <div class="text-xs mt-1 text-slate-400"><?php echo e($loc['address']); ?></div>
                </div>
            </div>
        </div>
    </section>

    <div class="grid grid-cols-1 xl:grid-cols-[1fr_360px] gap-5">
        <section class="rounded-2xl border p-4 sm:p-5" style="background: var(--surface-3); border-color: var(--border);">
            <div class="flex items-center justify-between gap-3 mb-4">
                <div>
                    <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Role Activity Timeline</h2>
                    <p class="text-sm" style="color: var(--text-secondary);">Separate operational feed for <?php echo e($role->label()); ?>.</p>
                </div>
            </div>

            <div class="space-y-3">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $events; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $event): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div class="rounded-xl border p-4 flex gap-4" style="background: var(--surface-2); border-color: var(--border);">
                        <div class="shrink-0 rounded-xl px-3 py-2 text-sm font-bold text-center" style="background: <?php echo e($profile['accent']); ?>22; color: <?php echo e($profile['accent']); ?>;"><?php echo e($event['time']); ?></div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">
                                <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e($event['title']); ?></h3>
                                <span class="self-start rounded-full px-2.5 py-1 text-xs font-semibold" style="background: <?php echo e($profile['accent']); ?>18; color: <?php echo e($profile['accent']); ?>;"><?php echo e($event['status']); ?></span>
                            </div>
                            <p class="text-sm mt-1" style="color: var(--text-secondary);"><?php echo e($event['detail']); ?></p>
                        </div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </section>

        <aside class="space-y-5">
            <section class="rounded-2xl border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <h2 class="text-base font-semibold" style="color: var(--text-primary);">Location Desk</h2>
                <div class="space-y-3 mt-3">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $locations; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $location): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <div class="rounded-xl border p-3" style="border-color: var(--border); background: var(--surface-2);">
                            <div class="text-sm font-semibold" style="color: var(--text-primary);"><?php echo e($location['name']); ?></div>
                            <div class="text-xs mt-1" style="color: var(--text-muted);"><?php echo e($location['code']); ?></div>
                            <div class="text-xs mt-2" style="color: var(--text-secondary);"><?php echo e($location['owner']); ?> - <?php echo e($location['phone']); ?></div>
                            <div class="text-xs mt-1" style="color: var(--text-muted);"><?php echo e($location['address']); ?></div>
                        </div>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </section>

            <section class="rounded-2xl border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <h2 class="text-base font-semibold" style="color: var(--text-primary);">Active Packaging SKUs</h2>
                <div class="space-y-2 mt-3">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $skuSamples; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $sku): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <div class="rounded-lg border px-3 py-2" style="border-color: var(--border);">
                            <div class="text-sm font-semibold" style="color: var(--text-primary);"><?php echo e($sku['sku']); ?></div>
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);"><?php echo e($sku['name']); ?></div>
                        </div>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </section>
        </aside>
    </div>

    <section class="rounded-2xl border p-4 sm:p-5" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="text-lg font-semibold" style="color: var(--text-primary);">System Audit Trail</h2>
        <p class="text-sm mb-4" style="color: var(--text-secondary);">
            <?php echo e($role === \App\Enums\Role::Admin ? 'Live audit entries from every role.' : 'Live audit entries related to this role.'); ?>

        </p>

        <div class="flex flex-col divide-y rounded-xl border overflow-hidden" style="border-color: var(--border); background: var(--surface-2);">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $realRows; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $row): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <div class="px-4 py-3 flex items-center justify-between gap-3" style="border-color: var(--border);">
                    <div class="min-w-0">
                        <div class="text-sm font-medium truncate" style="color: var(--text-primary);"><?php echo e($row->action); ?></div>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($row->detail): ?>
                            <div class="text-xs mt-0.5 truncate" style="color: var(--text-muted);"><?php echo e($row->detail); ?></div>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                    <span class="text-xs shrink-0" style="color: var(--text-muted);"><?php echo e($row->created_at->format('d M, H:i')); ?></span>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <div class="text-center text-sm py-8" style="color: var(--text-muted);">No live audit rows for this role yet.</div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    </section>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\shared\activity-log.blade.php ENDPATH**/ ?>