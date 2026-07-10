<?php

use App\Models\VendorMaster;
use App\Models\VendorStockUpdate;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="max-w-5xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
        <div>
            <h1 class="text-xl sm:text-2xl font-semibold" style="color: var(--text-primary);">Vendor Master</h1>
            <p class="text-sm mt-1" style="color: var(--text-secondary);">Every registered vendor, matching the client's Zoho CRM Vendors module, plus the GST our own gate check needs.</p>
        </div>
        <button wire:click="$toggle('adding')" class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">
            <?php echo e($adding ? 'Cancel' : 'Add vendor'); ?>

        </button>
    </div>


    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($adding): ?>
        <div class="rounded-lg border p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3" style="background: var(--surface-3); border-color: var(--border);">
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Vendor name</span>
                <input wire:model="vendorName" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
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
                <span class="font-medium" style="color: var(--text-primary);">GST number</span>
                <input wire:model="gstNumber" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="27AACCH1234K1Z5" />
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['gstNumber'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-xs" style="color: var(--status-critical);"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Contact phone</span>
                <input wire:model="contactPhone" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Contact email</span>
                <input wire:model="contactEmail" type="email" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Website</span>
                <input wire:model="website" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Category</span>
                <input wire:model="category" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">GL Account</span>
                <select wire:model="glAccount" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $glAccounts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $g): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?> <option value="<?php echo e($g); ?>"><?php echo e($g); ?></option> <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </select>
            </label>
            <div class="grid grid-cols-2 gap-3">
                <label class="flex flex-col gap-1.5 text-sm">
                    <span class="font-medium" style="color: var(--text-primary);">City</span>
                    <input wire:model="addressCity" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                </label>
                <label class="flex flex-col gap-1.5 text-sm">
                    <span class="font-medium" style="color: var(--text-primary);">State</span>
                    <input wire:model="addressState" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                </label>
            </div>
            <label class="flex flex-col gap-1.5 text-sm sm:col-span-2">
                <span class="font-medium" style="color: var(--text-primary);">Description</span>
                <textarea wire:model="description" rows="2" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);"></textarea>
            </label>
            <label class="flex items-center gap-2 text-sm sm:col-span-2">
                <input wire:model="active" type="checkbox" class="w-4 h-4" />
                <span style="color: var(--text-primary);">Active vendor</span>
            </label>
            <button wire:click="addVendor" class="sm:col-span-2 rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Add to master</button>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="px-2 py-2.5"></th>
                        <th class="px-4 py-2.5 font-medium">Vendor Name</th>
                        <th class="px-4 py-2.5 font-medium">Email</th>
                        <th class="px-4 py-2.5 font-medium">Phone</th>
                        <th class="px-4 py-2.5 font-medium">GST</th>
                        <th class="px-4 py-2.5 font-medium">Active</th>
                        <th class="px-4 py-2.5 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $vendors; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $v): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-2 py-2.5">
                                <button wire:click="$set('expanded', <?php echo e($expanded === $v->id ? 'null' : $v->id); ?>)" style="color: var(--text-muted);"><?php echo e($expanded === $v->id ? '▾' : '▸'); ?></button>
                            </td>
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);"><?php echo e($v->vendor_name); ?></td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);"><?php echo e($v->contact_email ?? '—'); ?></td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);"><?php echo e($v->contact_phone); ?></td>
                            <td class="px-4 py-2.5 font-mono text-xs" style="color: var(--text-secondary);"><?php echo e($v->gst_number); ?></td>
                            <td class="px-4 py-2.5"><?php echo e($v->active ? 'Yes' : 'No'); ?></td>
                            <td class="px-4 py-2.5 text-right">
                                <button wire:click="deleteVendor(<?php echo e($v->id); ?>)" wire:confirm="Remove <?php echo e($v->vendor_name); ?>?" style="color: var(--status-critical);">Remove</button>
                            </td>
                        </tr>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($expanded === $v->id): ?>
                            <tr style="background: var(--surface-2);">
                                <td></td>
                                <td colspan="6" class="px-4 py-3">
                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Category</div><div style="color: var(--text-primary);"><?php echo e($v->category); ?></div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">GL Account</div><div style="color: var(--text-primary);"><?php echo e($v->gl_account ?? '—'); ?></div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Website</div><div style="color: var(--text-primary);"><?php echo e($v->website ?? '—'); ?></div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Location</div><div style="color: var(--text-primary);"><?php echo e($v->address_city ? "{$v->address_city}, {$v->address_state}" : '—'); ?></div></div>
                                    </div>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($v->description): ?>
                                        <div class="text-xs mt-2"><span class="font-semibold" style="color: var(--text-muted);">Description: </span><span style="color: var(--text-primary);"><?php echo e($v->description); ?></span></div>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr><td colspan="7" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No vendors registered yet.</td></tr>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <h2 class="font-semibold text-sm mt-8 mb-3" style="color: var(--text-primary);">Vendor stock updates</h2>
    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="px-4 py-2.5 font-medium">Vendor</th>
                        <th class="px-4 py-2.5 font-medium">Material</th>
                        <th class="px-4 py-2.5 font-medium">Quantity</th>
                        <th class="px-4 py-2.5 font-medium">Note</th>
                        <th class="px-4 py-2.5 font-medium">Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $stockUpdates; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $u): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);"><?php echo e($u->vendor_name); ?></td>
                            <td class="px-4 py-2.5" style="color: var(--text-secondary);"><?php echo e($u->material); ?></td>
                            <td class="px-4 py-2.5" style="color: var(--text-primary);"><?php echo e($u->quantity); ?> <?php echo e($u->unit); ?></td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-muted);"><?php echo e($u->note ?? '—'); ?></td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-muted);"><?php echo e($u->created_at->format('d M Y, H:i')); ?></td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr><td colspan="5" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No vendor stock updates yet.</td></tr>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\admin\vendor-master.blade.php ENDPATH**/ ?>