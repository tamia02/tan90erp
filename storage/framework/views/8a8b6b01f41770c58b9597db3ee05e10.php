<?php

use App\Models\GateEntry;
use App\Models\ValidationIssue;
use App\Services\AuditLogger;
use App\Services\GateValidationService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="space-y-5">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($saved): ?>
        <section class="rounded-2xl border p-6 text-center" style="background: var(--surface-3); border-color: var(--border);">
            <div class="mx-auto w-14 h-14 rounded-2xl grid place-items-center text-xl font-black" style="background: var(--status-good-bg); color: var(--status-good);">OK</div>
            <h1 class="text-2xl font-bold mt-4" style="color: var(--text-primary);">Entry saved</h1>
            <p class="text-sm mt-1" style="color: var(--text-secondary);"><?php echo e($saved['gate']->gate_no); ?> - <?php echo e($saved['location']['code']); ?></p>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-5 text-left">
                <div class="rounded-xl border p-4" style="border-color: var(--border); background: var(--surface-2);">
                    <div class="text-xs uppercase" style="color: var(--text-muted);"><?php echo e(ucfirst($saved['gate']->entry_type)); ?></div>
                    <div class="font-semibold mt-1" style="color: var(--text-primary);"><?php echo e($saved['gate']->vendor_name); ?></div>
                    <div class="text-xs mt-1" style="color: var(--text-secondary);"><?php echo e($saved['gate']->po_number); ?></div>
                </div>
                <div class="rounded-xl border p-4" style="border-color: var(--border); background: var(--surface-2);">
                    <div class="text-xs uppercase" style="color: var(--text-muted);">Person / Vehicle</div>
                    <div class="font-semibold mt-1" style="color: var(--text-primary);"><?php echo e($saved['gate']->driver_name); ?></div>
                    <div class="text-xs mt-1" style="color: var(--text-secondary);"><?php echo e($saved['gate']->vehicle_number); ?></div>
                </div>
                <div class="rounded-xl border p-4" style="border-color: var(--border); background: var(--surface-2);">
                    <div class="text-xs uppercase" style="color: var(--text-muted);">Next Step</div>
                    <div class="font-semibold mt-1" style="color: var(--text-primary);"><?php echo e($saved['gate']->entry_type === 'inward' ? 'Send to unloading' : ($saved['gate']->entry_type === 'outward' ? 'Confirm exit' : 'Gate pass active')); ?></div>
                    <div class="text-xs mt-1" style="color: var(--text-secondary);"><?php echo e(count($saved['issues']) ? count($saved['issues']).' issue(s)' : 'No blocking issue'); ?></div>
                </div>
            </div>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(count($saved['issues'])): ?>
                <div class="rounded-xl p-4 mt-5 text-left text-sm" style="background: var(--status-critical-bg); color: var(--status-critical);">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $saved['issues']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $issue): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <div><strong><?php echo e($issue['title']); ?></strong> - <?php echo e($issue['description']); ?></div>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            <?php else: ?>
                <div class="rounded-xl p-4 mt-5 text-sm" style="background: var(--status-good-bg); color: var(--status-good);">Saved successfully. No blocking issue found.</div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mt-5">
                <button wire:click="resetForm" class="rounded-xl px-4 py-3 text-sm font-semibold border" style="border-color: var(--border); color: var(--text-primary);">New <?php echo e(strtolower($modeLabel)); ?></button>
                <a href="<?php echo e($saved['gate']->entry_type === 'inward' ? route('unloading.dashboard') : route('guard.entries')); ?>" class="rounded-xl px-4 py-3 text-sm font-semibold text-white text-center" style="background: var(--brand);"><?php echo e($saved['gate']->entry_type === 'inward' ? 'Send to Unloading' : 'View Entries'); ?></a>
                <a href="<?php echo e(route('guard.entries')); ?>" wire:navigate class="rounded-xl px-4 py-3 text-sm font-semibold border text-center" style="border-color: var(--border); color: var(--text-primary);">Guard Entries</a>
            </div>
        </section>
    <?php else: ?>
        <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Guard Module</div>
                    <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);"><?php echo e($modeLabel); ?> Entry</h1>
                    <p class="text-sm mt-1" style="color: var(--text-secondary);">Simple gate form. Select type, quick fill or enter details, capture GPS, then save.</p>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = ['inward' => 'Inward', 'outward' => 'Outward', 'visitor' => 'Visitor']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $value => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <button wire:click="setEntryType('<?php echo e($value); ?>')" class="rounded-xl px-4 py-3 text-sm font-semibold border" style="<?php echo e($entryType === $value ? 'background: var(--brand); color: white; border-color: var(--brand);' : 'border-color: var(--border); color: var(--text-primary);'); ?>"><?php echo e($label); ?></button>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </div>
        </section>


        <div class="grid grid-cols-1 xl:grid-cols-[1fr_340px] gap-5">
            <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex flex-col md:flex-row gap-3 md:items-center md:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold" style="color: var(--text-primary);"><?php echo e($entryType === 'visitor' ? 'Visitor Pass' : 'Scan / Autofill'); ?></h2>
                        <p class="text-sm mt-1" style="color: var(--text-secondary);"><?php echo e($entryType === 'visitor' ? 'No bill upload for visitor entry.' : 'Use quick scan, or upload/capture bill if needed.'); ?></p>
                    </div>
                    <div class="flex flex-col sm:flex-row gap-2">
                        <button type="button" wire:click="fillSample" class="rounded-xl px-4 py-3 text-sm font-bold text-white" style="background: var(--brand);"><?php echo e($entryType === 'visitor' ? 'Quick Visitor Fill' : 'Quick Scan Autofill'); ?></button>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($entryType === 'visitor')): ?>
                            <label class="rounded-xl px-4 py-3 text-sm font-semibold border cursor-pointer text-center" style="border-color: var(--border); color: var(--text-primary);">
                                Camera / Upload
                                <input type="file" accept="image/*,.pdf" capture="environment" class="hidden" wire:click="$set('billScanned', true)" />
                            </label>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-5">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($entryType === 'visitor'): ?>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Visitor Name</span>
                            <input wire:model="driverName" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Visitor name" />
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['driverName'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-xs" style="color: var(--status-critical);"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Mobile</span>
                            <input wire:model="driverPhone" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="+91 ..." />
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['driverPhone'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-xs" style="color: var(--status-critical);"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Company</span>
                            <input wire:model="vendorName" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Company / agency" />
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Person To Meet</span>
                            <input wire:model="poNumber" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Store Manager" />
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['poNumber'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-xs" style="color: var(--status-critical);"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </label>
                        <label class="space-y-1.5 text-sm md:col-span-2">
                            <span class="font-semibold" style="color: var(--text-primary);">Purpose</span>
                            <input wire:model="material" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Meeting / service / audit" />
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['material'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-xs" style="color: var(--status-critical);"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </label>
                    <?php else: ?>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);"><?php echo e($entryType === 'outward' ? 'Page Number' : 'PO Number'); ?></span>
                            <input wire:model="poNumber" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="<?php echo e($entryType === 'outward' ? 'OUT RM 2627 0020' : 'PO RM 2627 0020'); ?>" />
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['poNumber'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-xs" style="color: var(--status-critical);"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);"><?php echo e($entryType === 'outward' ? 'Delivery Address' : 'Vendor'); ?></span>
                            <input wire:model="vendorName" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="<?php echo e($entryType === 'outward' ? 'Delivery address' : 'Vendor'); ?>" />
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['vendorName'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-xs" style="color: var(--status-critical);"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Invoice / Doc No</span>
                            <input wire:model="invoiceNumber" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Invoice number" />
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['invoiceNumber'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-xs" style="color: var(--status-critical);"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Qty</span>
                            <input wire:model="invoiceQty" type="number" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="700" />
                        </label>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($entryType === 'inward'): ?>
                            <label class="space-y-1.5 text-sm">
                                <span class="font-semibold" style="color: var(--text-primary);">PO Bill Date</span>
                                <input wire:model="poBillDate" type="date" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" />
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['poBillDate'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-xs" style="color: var(--status-critical);"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </label>
                            <label class="space-y-1.5 text-sm">
                                <span class="font-semibold" style="color: var(--text-primary);">Invoice Amount</span>
                                <input wire:model="invoiceAmount" type="number" step="0.01" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="29400" />
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['invoiceAmount'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-xs" style="color: var(--status-critical);"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </label>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <label class="space-y-1.5 text-sm md:col-span-2">
                            <span class="font-semibold" style="color: var(--text-primary);">Material / SKU</span>
                            <input wire:model="material" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="PCM Raw Compound (TN-1 Grade)" />
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['material'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-xs" style="color: var(--status-critical);"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </label>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                    <label class="space-y-1.5 text-sm">
                        <span class="font-semibold" style="color: var(--text-primary);"><?php echo e($entryType === 'visitor' ? 'Vehicle / Walk-in' : 'Vehicle Number'); ?></span>
                        <input wire:model="vehicleNumber" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="<?php echo e($entryType === 'visitor' ? 'WALK-IN' : 'MH 04 GT 5521'); ?>" />
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['vehicleNumber'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-xs" style="color: var(--status-critical);"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </label>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($entryType === 'visitor')): ?>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Driver Name</span>
                            <input wire:model="driverName" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Driver name" />
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['driverName'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span class="text-xs" style="color: var(--status-critical);"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </label>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>

                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($entryType === 'visitor')): ?>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mt-5">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = ['invoice' => 'Invoice', 'eway' => 'E-way', 'lr' => 'LR/LRC', 'pod' => 'POD']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <?php if($key === 'pod' && $entryType === 'outward') continue; ?>
                            <label class="flex items-center justify-between gap-2 rounded-xl border px-3 py-2.5 text-sm" style="border-color: var(--border); background: var(--surface-2); color: var(--text-primary);">
                                <span><?php echo e($label); ?></span>
                                <input wire:model.live="documents.<?php echo e($key); ?>" type="checkbox" class="w-5 h-5 rounded" />
                            </label>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                <label class="block mt-5 space-y-1.5 text-sm">
                    <span class="font-semibold" style="color: var(--text-primary);">Remarks</span>
                    <textarea wire:model="remarks" rows="2" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Optional note"></textarea>
                </label>
            </section>

            <aside class="space-y-5">
                <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
                    <h2 class="font-semibold" style="color: var(--text-primary);">Location</h2>
                    <select wire:model.live="location" class="mt-3 w-full rounded-xl border px-3 py-2.5 text-sm" style="border-color: var(--border); color: var(--text-primary); background: var(--surface-2);">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $locationDetails; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $loc): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($key); ?>"><?php echo e($loc['name']); ?></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </select>
                    <div class="rounded-xl border p-3 mt-3 text-xs" style="border-color: var(--border); background: var(--surface-2); color: var(--text-secondary);">
                        <strong style="color: var(--text-primary);"><?php echo e($selectedLocation['code']); ?></strong><br>
                        <?php echo e($selectedLocation['contact']); ?> - <?php echo e($selectedLocation['phone']); ?><br>
                        <?php echo e($selectedLocation['address']); ?>

                    </div>
                    <button type="button" wire:click="useGps" class="mt-3 w-full rounded-xl border px-3 py-3 text-sm font-semibold" style="border-color: var(--border); color: var(--text-primary);"><?php echo e($gps ?: 'Capture GPS'); ?></button>
                </section>

                <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
                    <div class="grid grid-cols-2 gap-3 text-center">
                        <div class="rounded-xl border p-3" style="border-color: var(--border); background: var(--surface-2);">
                            <div class="text-xl font-bold" style="color: var(--text-primary);"><?php echo e($entryType === 'visitor' ? 'Pass' : $documentCount.'/4'); ?></div>
                            <div class="text-xs" style="color: var(--text-muted);"><?php echo e($entryType === 'visitor' ? 'Type' : 'Docs'); ?></div>
                        </div>
                        <div class="rounded-xl border p-3" style="border-color: var(--border); background: var(--surface-2);">
                            <div class="text-xl font-bold" style="color: var(--text-primary);"><?php echo e($gps ? 'Yes' : 'No'); ?></div>
                            <div class="text-xs" style="color: var(--text-muted);">GPS</div>
                        </div>
                    </div>
                    <button wire:click="saveEntry" class="mt-4 w-full rounded-xl px-4 py-3 text-sm font-bold text-white" style="background: var(--brand);">
                        <?php echo e($entryType === 'visitor' ? 'Save Visitor Pass' : ($entryType === 'outward' ? 'Save Outward Entry' : 'Save and Send to Unloading')); ?>

                    </button>
                </section>
            </aside>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\guard\bill-scan.blade.php ENDPATH**/ ?>