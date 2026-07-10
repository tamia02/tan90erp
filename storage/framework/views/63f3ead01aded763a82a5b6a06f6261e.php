<?php

use App\Models\SkuMaster;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="max-w-5xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
        <div>
            <h1 class="text-xl sm:text-2xl font-semibold" style="color: var(--text-primary);">SKU Master</h1>
            <p class="text-sm mt-1" style="color: var(--text-secondary);">Every product, matching the client's Zoho CRM Products module — Guard Bill Scan checks mapped status against this list.</p>
        </div>
        <button wire:click="$toggle('adding')" class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">
            <?php echo e($adding ? 'Cancel' : 'Add SKU'); ?>

        </button>
    </div>


    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($adding): ?>
        <div class="rounded-lg border p-4 mb-4 grid grid-cols-1 sm:grid-cols-3 gap-3" style="background: var(--surface-3); border-color: var(--border);">
            <label class="flex flex-col gap-1.5 text-sm sm:col-span-2">
                <span class="font-medium" style="color: var(--text-primary);">Product Name</span>
                <input wire:model="sku" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="PCM Raw Compound (TN-1 Grade)" />
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['sku'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-xs" style="color: var(--status-critical);"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Product Code</span>
                <input wire:model="productCode" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Category</span>
                <select wire:model="category" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $categories; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $c): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?> <option value="<?php echo e($c); ?>"><?php echo e($c); ?></option> <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </select>
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Usage Unit</span>
                <select wire:model="unit" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $units; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $u): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?> <option value="<?php echo e($u); ?>"><?php echo e($u); ?></option> <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </select>
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Vendor Name</span>
                <input wire:model="vendorName" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Unit Price (₹)</span>
                <input wire:model="unitPrice" type="number" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Quantity in Stock</span>
                <input wire:model="quantityInStock" type="number" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Reorder Level</span>
                <input wire:model="reorderLevel" type="number" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Default bin (Tan90-only)</span>
                <input wire:model="defaultBin" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="BHW-PCM-A1" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm sm:col-span-3">
                <span class="font-medium" style="color: var(--text-primary);">Description</span>
                <textarea wire:model="description" rows="2" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);"></textarea>
            </label>
            <label class="flex items-center gap-2 text-sm sm:col-span-3">
                <input wire:model="mapped" type="checkbox" class="w-4 h-4" />
                <span style="color: var(--text-primary);">Mapped (Tan90-only) — passes the "SKU not mapped" gate check</span>
            </label>
            <button wire:click="addSku" class="sm:col-span-3 rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Add to master</button>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="px-2 py-2.5"></th>
                        <th class="px-4 py-2.5 font-medium">Product Name</th>
                        <th class="px-4 py-2.5 font-medium">Product Code</th>
                        <th class="px-4 py-2.5 font-medium">Mapped</th>
                        <th class="px-4 py-2.5 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $skus; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $s): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-2 py-2.5">
                                <button wire:click="$set('expanded', <?php echo e($expanded === $s->id ? 'null' : $s->id); ?>)" style="color: var(--text-muted);"><?php echo e($expanded === $s->id ? '▾' : '▸'); ?></button>
                            </td>
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);"><?php echo e($s->sku); ?></td>
                            <td class="px-4 py-2.5 text-xs font-mono" style="color: var(--text-secondary);"><?php echo e($s->product_code ?? '—'); ?></td>
                            <td class="px-4 py-2.5"><?php echo e($s->mapped ? 'Yes' : 'No'); ?></td>
                            <td class="px-4 py-2.5 text-right">
                                <button wire:click="deleteSku(<?php echo e($s->id); ?>)" wire:confirm="Remove <?php echo e($s->sku); ?>?" style="color: var(--status-critical);">Remove</button>
                            </td>
                        </tr>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($expanded === $s->id): ?>
                            <tr style="background: var(--surface-2);">
                                <td></td>
                                <td colspan="4" class="px-4 py-3">
                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Category</div><div style="color: var(--text-primary);"><?php echo e($s->category); ?></div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Vendor</div><div style="color: var(--text-primary);"><?php echo e($s->vendor_name ?? '—'); ?></div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Unit Price</div><div style="color: var(--text-primary);"><?php echo e($s->unit_price ? '₹'.$s->unit_price : '—'); ?></div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Qty in Stock</div><div style="color: var(--text-primary);"><?php echo e($s->quantity_in_stock ?? '—'); ?></div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Reorder Level</div><div style="color: var(--text-primary);"><?php echo e($s->reorder_level ?? '—'); ?></div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Default Bin</div><div style="color: var(--text-primary);"><?php echo e($s->default_bin ?? '—'); ?></div></div>
                                    </div>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($s->description): ?>
                                        <div class="text-xs mt-2"><span class="font-semibold" style="color: var(--text-muted);">Description: </span><span style="color: var(--text-primary);"><?php echo e($s->description); ?></span></div>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr><td colspan="5" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No SKUs mapped yet.</td></tr>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\admin\sku-master.blade.php ENDPATH**/ ?>