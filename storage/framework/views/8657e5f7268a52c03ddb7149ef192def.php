<?php

use App\Models\GateEntry;
use App\Models\User;
use App\Models\ValidationIssue;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="max-w-5xl mx-auto">
    <div class="mb-5">
        <h1 class="text-xl sm:text-2xl font-semibold" style="color: var(--text-primary);">Admin Overview</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Welcome, <?php echo e(auth()->user()->name); ?>.</p>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Team Members</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);"><?php echo e($userCount); ?></div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Open Issues</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--status-warning);"><?php echo e($openIssues); ?></div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Gates In Flight</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);"><?php echo e($gatesInFlight); ?></div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Gates Closed</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--status-good);"><?php echo e($gatesClosed); ?></div>
        </div>
    </div>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\admin\overview.blade.php ENDPATH**/ ?>