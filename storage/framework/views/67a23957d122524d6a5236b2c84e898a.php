<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="max-w-4xl mx-auto">
    <div class="mb-5">
        <h1 class="text-xl sm:text-2xl font-semibold" style="color: var(--text-primary);">Integrations</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Connect the Zoho CRM organization to pull vendor and purchase order details into Tan90.</p>
    </div>


    <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex items-start gap-3">
            <div class="w-10 h-10 rounded-lg grid place-items-center shrink-0" style="background: <?php echo e($connected ? 'var(--status-good-bg)' : 'var(--surface-2)'); ?>; color: <?php echo e($connected ? 'var(--status-good)' : 'var(--text-muted)'); ?>;">Z</div>
            <div class="min-w-0">
                <div class="text-sm font-medium" style="color: var(--text-primary);"><?php echo e($connected ? 'Connected' : 'Not connected'); ?></div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($connected): ?>
                    <p class="text-xs mt-0.5" style="color: var(--text-muted);">Pulling PO data from Zoho's Purchase Orders module (<?php echo e(config('services.zoho.po_module_api_name')); ?>).</p>
                <?php else: ?>
                    <p class="text-xs mt-0.5" style="color: var(--text-muted);">Set ZOHO_CLIENT_ID, ZOHO_CLIENT_SECRET and ZOHO_REFRESH_TOKEN in .env (see .env.example) once the client's OAuth setup is done.</p>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </div>
    </div>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\admin\integrations.blade.php ENDPATH**/ ?>