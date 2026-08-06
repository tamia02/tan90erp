<?php

use App\Livewire\Actions\Logout;
use App\Services\Access\AccessControlService;
use App\Support\RoleNavigation;
use App\Support\Tan90ModuleNavigation;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }

    public function with(): array
    {
        $user = auth()->user();

        if (! $user) {
            return ['navItems' => []];
        }

        $access = app(AccessControlService::class);
        $accessItems = [];
        if ($access->hasNewAccess($user)) {
            foreach ([
                // No self-service "Workspace" / "Customise Workspace" links -
                // per the client, nobody builds their own dashboard. A
                // Head/Manager's combined dashboard is configured by Super
                // Admin when the profile is created (People > Add User),
                // not something the person themselves edits.
                ['label' => 'Access Roles', 'route' => 'access.roles.index', 'permission' => 'access.roles.view'],
                ['label' => 'People', 'route' => 'access.people.index', 'permission' => 'access.people.view'],
                ['label' => 'Hierarchy', 'route' => 'access.hierarchy.index', 'permission' => 'access.hierarchy.view'],
                ['label' => 'Saved Views', 'route' => 'access.views.index', 'permission' => 'views.use_assigned'],
                ['label' => 'Access Activity', 'route' => 'access.activity.index', 'permission' => 'access.activity.view'],
                ['label' => 'Access Simulator', 'route' => 'access.simulator.index', 'permission' => 'access.simulator.view'],
                ['label' => 'Forge (Manufacturing)', 'route' => 'forge.dashboard', 'permission' => 'forge.dashboard.view'],
                ['label' => 'Flow (Warehouse & Delivery)', 'route' => 'flow.dashboard', 'permission' => 'flow.dashboard.view'],
            ] as $item) {
                $canSeeItem = isset($item['permission_any'])
                    ? collect($item['permission_any'])->contains(fn ($permission) => $access->can($user, $permission))
                    : $access->can($user, $item['permission']);

                if ($canSeeItem) {
                    unset($item['permission']);
                    unset($item['permission_any']);
                    $accessItems[] = $item + ['icon' => 'shield'];
                }
            }
        }

        // GRN users keep their existing per-role nav, unchanged unless they
        // explicitly have new access assignments. A Tan90-only
        // user (Master Data/BOM role, no GRN role) gets nav items for
        // whichever module's routes they're currently on - not a fixed set
        // per role, since a shared role like Super Admin moves between
        // modules.
        if ($user->role) {
            return ['navItems' => [...RoleNavigation::forRole($user->role), ...$accessItems]];
        }

        if (request()->routeIs('tan90.brc.*')) {
            return ['navItems' => [...Tan90ModuleNavigation::forBom(), ...$accessItems]];
        }

        if (request()->routeIs('tan90.master-data.*')) {
            return ['navItems' => [...Tan90ModuleNavigation::forMasterData(), ...$accessItems]];
        }

        if (request()->routeIs('forge.*')) {
            $forgeItems = [];
            foreach ([
                ['label' => 'Command Centre', 'route' => 'forge.dashboard', 'permission' => 'forge.dashboard.view'],
                ['label' => 'Production Plans', 'route' => 'forge.plans.index', 'permission' => 'forge.plan.view'],
                ['label' => 'Work Orders', 'route' => 'forge.workorders.index', 'permission' => 'forge.workorder.view'],
                ['label' => 'Machines & OEE', 'route' => 'forge.machines.index', 'permission' => 'forge.machine.view'],
                ['label' => 'Wastage & Scrap', 'route' => 'forge.wastage.index', 'permission' => 'forge.wastage.record'],
                ['label' => 'In-Process Quality', 'route' => 'forge.quality-holds.index', 'permission' => 'forge.ipqc.record'],
                ['label' => 'Final QC & FG Release', 'route' => 'forge.final-qc.index', 'permission' => 'forge.finalqc.record'],
                ['label' => 'Deviation & CAPA', 'route' => 'forge.deviations.index', 'permission' => 'forge.deviation.view'],
                ['label' => 'Batch Genealogy', 'route' => 'forge.batches.index', 'permission' => 'forge.batch.trace'],
            ] as $item) {
                if ($access->can($user, $item['permission'])) {
                    unset($item['permission']);
                    $forgeItems[] = $item + ['icon' => 'factory'];
                }
            }

            return ['navItems' => [...$forgeItems, ...$accessItems]];
        }

        if (request()->routeIs('flow.*')) {
            $flowItems = [];
            foreach ([
                ['label' => 'Command Centre', 'route' => 'flow.dashboard', 'permission' => 'flow.dashboard.view'],
                ['label' => 'FG Inventory & Ledger', 'route' => 'flow.inventory.index', 'permission' => 'flow.inventory.view'],
                ['label' => 'Customer Orders', 'route' => 'flow.orders.index', 'permission' => 'flow.order.view'],
                ['label' => 'Wave Builder & Picking', 'route' => 'flow.waves.index', 'permission' => 'flow.wave.manage'],
                ['label' => 'Packing', 'route' => 'flow.packing.index', 'permission' => 'flow.pack.manage'],
                ['label' => 'Dispatch', 'route' => 'flow.dispatch.index', 'permission' => 'flow.dispatch.manage'],
                ['label' => 'Delivery & POD', 'route' => 'flow.deliveries.index', 'permission' => 'flow.delivery.manage'],
                ['label' => 'Returns, RMA & Claims', 'route' => 'flow.returns.index', 'permission' => 'flow.return.manage'],
            ] as $item) {
                if ($access->can($user, $item['permission'])) {
                    unset($item['permission']);
                    $flowItems[] = $item + ['icon' => 'truck'];
                }
            }

            return ['navItems' => [...$flowItems, ...$accessItems]];
        }

        return ['navItems' => $accessItems];
    }
}; ?>

<aside class="w-64 shrink-0 border-r flex flex-col" style="background: var(--surface-1); border-color: var(--border);">
    <div class="flex items-center gap-2.5 px-5 py-5 border-b" style="border-color: var(--border);">
        <div class="w-9 h-9 rounded-lg grid place-items-center font-bold text-sm text-white" style="background: var(--brand);">
            T90
        </div>
        <span class="font-semibold tracking-wide" style="color: var(--text-primary);">Tan90 ERP</span>
    </div>

    <nav class="flex-1 overflow-y-auto py-3 px-2.5 space-y-0.5">
        @foreach ($navItems as $item)
            <a
                href="{{ route($item['route'], $item['params'] ?? []) }}"
                wire:navigate
                @class([
                    'flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm font-medium transition-colors',
                ])
                style="{{ request()->routeIs($item['route']) && request()->route('entity') == ($item['params'][0] ?? request()->route('entity')) ? 'background: var(--brand-bg); color: var(--brand);' : 'color: var(--text-secondary);' }}"
            >
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>

    <div class="px-3 py-4 border-t" style="border-color: var(--border);">
        <div class="px-2 mb-2">
            <div class="text-sm font-medium truncate" style="color: var(--text-primary);">{{ auth()->user()->name }}</div>
            <div class="text-xs truncate" style="color: var(--text-muted);">{{ auth()->user()->role?->label() ?? auth()->user()->tan90Profile?->role?->name ?? 'No role assigned' }}</div>
        </div>
        <button
            wire:click="logout"
            class="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors"
            style="color: var(--status-critical);"
        >
            Log out
        </button>
    </div>
</aside>
