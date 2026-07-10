<?php

use App\Models\LedgerEntry;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Stock Ledger</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Every posting made by GRN Check — immutable, append-only.</p>

    <?php echo $__env->make('partials.stock-ledger-table', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div><?php /**PATH C:\Users\tasmi\AppData\Local\Packages\5319275A.WhatsAppDesktop_cv1g1gvanyjgm\LocalState\sessions\C58B0E0E1F1E5561213D0701C5EBD37C2F6DB401\transfers\2026-28\tan90-mod1-current-code-20260708-103300\full_project\resources\views\livewire\grn\stock-ledger.blade.php ENDPATH**/ ?>