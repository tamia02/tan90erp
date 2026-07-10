<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="max-w-lg mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Settings</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Your personal preferences for this account.</p>

    <div class="rounded-lg border p-4 flex flex-col gap-4" style="background: var(--surface-3); border-color: var(--border);">
        <label class="flex items-center gap-2 text-sm">
            <input wire:model="emailNotifications" type="checkbox" class="w-4 h-4" />
            <span style="color: var(--text-primary);">Email notifications</span>
        </label>
        <label class="flex items-center gap-2 text-sm">
            <input wire:model="smsAlerts" type="checkbox" class="w-4 h-4" />
            <span style="color: var(--text-primary);">SMS alerts</span>
        </label>
        <label class="flex flex-col gap-1.5 text-sm">
            <span class="font-medium" style="color: var(--text-primary);">Theme</span>
            <select wire:model="theme" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                <option value="system">System</option>
                <option value="light">Light</option>
                <option value="dark">Dark</option>
            </select>
        </label>
        <div class="flex items-center gap-3">
            <button wire:click="save" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Save changes</button>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($justSaved): ?> <span class="text-xs" style="color: var(--status-good);">Saved</span> <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    </div>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\shared\settings.blade.php ENDPATH**/ ?>