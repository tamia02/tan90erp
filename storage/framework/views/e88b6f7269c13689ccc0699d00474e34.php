<?php

use App\Models\PurchaseOrder;
use App\Models\VendorMaster;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="max-w-6xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
        <div>
            <h1 class="text-xl sm:text-2xl font-semibold" style="color: var(--text-primary);">PO Master</h1>
            <p class="text-sm mt-1" style="color: var(--text-secondary);">Every purchase order, matching the client's Zoho CRM Purchase Orders module — Guard Bill Scan validates rate, quantity and vendor GST against this.</p>
        </div>
        <button wire:click="$toggle('adding')" class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">
            <?php echo e($adding ? 'Cancel' : 'Add PO'); ?>

        </button>
    </div>


    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($adding): ?>
        <div class="rounded-lg border p-4 mb-4 grid grid-cols-1 sm:grid-cols-3 gap-3" style="background: var(--surface-3); border-color: var(--border);">
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">PO Number</span>
                <input wire:model="poNumber" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="PO RM 2627 0099" />
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['poNumber'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-xs" style="color: var(--status-critical);"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Subject</span>
                <input wire:model="subject" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Vendor Name</span>
                <select wire:model="vendorName" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    <option value="">Choose vendor…</option>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $vendors; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $v): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?> <option value="<?php echo e($v->vendor_name); ?>"><?php echo e($v->vendor_name); ?></option> <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </select>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['vendorName'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-xs" style="color: var(--status-critical);"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">PO Date</span>
                <input wire:model="poDate" type="date" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Due Date</span>
                <input wire:model="dueDate" type="date" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Status</span>
                <select wire:model="status" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $statuses; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $s): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?> <option value="<?php echo e($s); ?>"><?php echo e($s); ?></option> <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </select>
            </label>
            <div class="sm:col-span-3 rounded-lg border p-3" style="border-color: var(--border);">
                <div class="text-xs font-semibold mb-2" style="color: var(--text-muted);">PURCHASE ITEM</div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <label class="flex flex-col gap-1.5 text-sm">
                        <span class="font-medium" style="color: var(--text-primary);">Product Name</span>
                        <input wire:model="product" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="PCM Raw Compound (TN-1 Grade)" />
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['product'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-xs" style="color: var(--status-critical);"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </label>
                    <label class="flex flex-col gap-1.5 text-sm">
                        <span class="font-medium" style="color: var(--text-primary);">Quantity</span>
                        <input wire:model="quantity" type="number" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['quantity'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-xs" style="color: var(--status-critical);"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </label>
                    <label class="flex flex-col gap-1.5 text-sm">
                        <span class="font-medium" style="color: var(--text-primary);">List Price (₹)</span>
                        <input wire:model="listPrice" type="number" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['listPrice'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-xs" style="color: var(--status-critical);"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </label>
                </div>
            </div>
            <button wire:click="addPo" class="sm:col-span-3 rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Add to master</button>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="px-2 py-2.5"></th>
                        <th class="px-4 py-2.5 font-medium">PO Number</th>
                        <th class="px-4 py-2.5 font-medium">Vendor</th>
                        <th class="px-4 py-2.5 font-medium">Status</th>
                        <th class="px-4 py-2.5 font-medium">Grand Total</th>
                        <th class="px-4 py-2.5 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $orders; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $po): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-2 py-2.5">
                                <button wire:click="$set('expanded', <?php echo e($expanded === $po->id ? 'null' : $po->id); ?>)" style="color: var(--text-muted);"><?php echo e($expanded === $po->id ? '▾' : '▸'); ?></button>
                            </td>
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);"><?php echo e($po->po_number); ?></td>
                            <td class="px-4 py-2.5" style="color: var(--text-secondary);"><?php echo e($po->vendor_name); ?></td>
                            <td class="px-4 py-2.5 text-xs font-medium" style="color: var(--text-secondary);"><?php echo e($po->status); ?></td>
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">₹<?php echo e(number_format($po->grandTotal(), 2)); ?></td>
                            <td class="px-4 py-2.5 text-right">
                                <button wire:click="deletePo(<?php echo e($po->id); ?>)" wire:confirm="Remove PO <?php echo e($po->po_number); ?>?" style="color: var(--status-critical);">Remove</button>
                            </td>
                        </tr>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($expanded === $po->id): ?>
                            <tr style="background: var(--surface-2);">
                                <td></td>
                                <td colspan="5" class="px-4 py-3">
                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs mb-2">
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Subject</div><div style="color: var(--text-primary);"><?php echo e($po->subject ?? '—'); ?></div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">PO Date</div><div style="color: var(--text-primary);"><?php echo e($po->po_date?->format('Y-m-d') ?? '—'); ?></div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Due Date</div><div style="color: var(--text-primary);"><?php echo e($po->due_date?->format('Y-m-d') ?? '—'); ?></div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Requisition #</div><div style="color: var(--text-primary);"><?php echo e($po->requisition_number ?? '—'); ?></div></div>
                                    </div>
                                    <div class="overflow-x-auto rounded-lg border" style="border-color: var(--border);">
                                        <table class="w-full text-xs">
                                            <thead><tr style="color: var(--text-muted); background: var(--surface-3);"><th class="px-3 py-1.5 text-left">Product</th><th class="px-3 py-1.5 text-left">Qty</th><th class="px-3 py-1.5 text-left">List Price</th></tr></thead>
                                            <tbody>
                                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $po->lines; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $line): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                                    <tr style="border-top: 1px solid var(--border);">
                                                        <td class="px-3 py-1.5" style="color: var(--text-primary);"><?php echo e($line->product); ?></td>
                                                        <td class="px-3 py-1.5"><?php echo e($line->quantity); ?></td>
                                                        <td class="px-3 py-1.5">₹<?php echo e($line->list_price); ?></td>
                                                    </tr>
                                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr><td colspan="6" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No purchase orders yet.</td></tr>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\admin\po-master.blade.php ENDPATH**/ ?>