<?php

use App\Livewire\Actions\Logout;
use App\Enums\Role;
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

        // Super Admin sees every module from one sidebar, grouped under
        // headings, plus the sub-menu of whichever module they are inside.
        if ($user->isAdmin()) {
            return ['navItems' => $this->superAdminItems($user, $access)];
        }

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
        $navigationRole = $user->role;
        if ($navigationRole) {
            return ['navItems' => [...RoleNavigation::forRole($navigationRole), ...$this->extraRoleItems($user), ...$accessItems]];
        }

        if (request()->routeIs('tan90.brc.*')) {
            return ['navItems' => [...Tan90ModuleNavigation::forBom(), ...$accessItems]];
        }

        if (request()->routeIs('tan90.master-data.*')) {
            return ['navItems' => [...Tan90ModuleNavigation::forMasterData(), ...$accessItems]];
        }

        if (request()->routeIs('forge.*')) {
            return ['navItems' => [...$this->forgeItems($user, $access), ...$accessItems]];
        }

        if (request()->routeIs('flow.*')) {
            return ['navItems' => [...$this->flowItems($user, $access), ...$accessItems]];
        }

        return ['navItems' => $accessItems];
    }

    private function superAdminItems($user, AccessControlService $access): array
    {
        $moduleMenus = [
            'tan90.brc.*' => ['BOM, Recipe & Costing', fn () => Tan90ModuleNavigation::forBom()],
            'tan90.master-data.*' => ['Master Data', fn () => Tan90ModuleNavigation::forMasterData()],
            'forge.*' => ['Forge (Manufacturing)', fn () => $this->forgeItems($user, $access)],
            'flow.*' => ['Flow (Warehouse & Delivery)', fn () => $this->flowItems($user, $access)],
            'inventory.*' => ['Inventory', fn () => [
                ['label' => 'Items & Recipes', 'route' => 'inventory.items', 'icon' => 'list'],
                ['label' => 'Create Bundle', 'route' => 'inventory.production', 'icon' => 'factory'],
                ['label' => 'Outward Shipment', 'route' => 'inventory.dispatch', 'icon' => 'truck'],
                ['label' => 'Stock Summary', 'route' => 'inventory.stock', 'icon' => 'boxes'],
            ]],
        ];

        $items = [];
        foreach ($moduleMenus as $pattern => [$heading, $menu]) {
            if (request()->routeIs($pattern)) {
                $items = [['heading' => $heading], ...$menu()];
                break;
            }
        }

        $modules = $this->permitted($user, $access, [
            ['label' => 'Inventory (RM → FG → Dispatch)', 'route' => 'inventory.stock', 'icon' => 'boxes'],
            ['label' => 'BOM, Recipe & Costing', 'route' => 'tan90.brc.dashboard', 'icon' => 'flask'],
            ['label' => 'Forge (Manufacturing)', 'route' => 'forge.dashboard', 'icon' => 'factory', 'permission' => 'forge.dashboard.view'],
            ['label' => 'Flow (Warehouse & Delivery)', 'route' => 'flow.dashboard', 'icon' => 'truck', 'permission' => 'flow.dashboard.view'],
            ['label' => 'PO Control Tower', 'route' => 'grn.po-control-tower', 'icon' => 'search'],
        ]);

        $accessControl = $this->permitted($user, $access, [
            ['label' => 'Access Roles', 'route' => 'access.roles.index', 'icon' => 'shield', 'permission' => 'access.roles.view'],
            ['label' => 'People', 'route' => 'access.people.index', 'icon' => 'shield', 'permission' => 'access.people.view'],
            ['label' => 'Hierarchy', 'route' => 'access.hierarchy.index', 'icon' => 'shield', 'permission' => 'access.hierarchy.view'],
            ['label' => 'Saved Views', 'route' => 'access.views.index', 'icon' => 'shield', 'permission' => 'views.use_assigned'],
            ['label' => 'Access Activity', 'route' => 'access.activity.index', 'icon' => 'shield', 'permission' => 'access.activity.view'],
            ['label' => 'Access Simulator', 'route' => 'access.simulator.index', 'icon' => 'shield', 'permission' => 'access.simulator.view'],
        ]);

        return [
            ...$items,
            ['heading' => 'Super Admin'],
            ...RoleNavigation::forRole(Role::Admin),
            ...($modules ? [['heading' => 'All Modules'], ...$modules] : []),
            ...($accessControl ? [['heading' => 'Access Control'], ...$accessControl] : []),
        ];
    }

    // One headed section per extra role (user_extra_roles), skipping screens
    // the main role's menu already links to. Empty for single-role users.
    private function extraRoleItems($user): array
    {
        // Headings (no 'route') never count as "already shown" links.
        $seen = collect(RoleNavigation::forRole($user->role))->filter(fn ($item) => isset($item['route']))->map(fn ($item) => $item['route'].json_encode($item['params'] ?? []));
        $items = [];

        foreach ($user->extraRoles() as $role) {
            $screens = array_values(array_filter(
                RoleNavigation::ownScreensFor($role),
                fn ($item) => isset($item['route']) && ! $seen->contains($item['route'].json_encode($item['params'] ?? [])),
            ));
            if ($screens) {
                $items = [...$items, ['heading' => $role->label()], ...$screens];
                $seen = $seen->merge(array_map(fn ($item) => $item['route'].json_encode($item['params'] ?? []), $screens));
            }
        }

        return $items;
    }

    private function forgeItems($user, AccessControlService $access): array
    {
        return array_map(fn ($item) => $item + ['icon' => 'factory'], $this->permitted($user, $access, [
            ['label' => 'Command Centre', 'route' => 'forge.dashboard', 'permission' => 'forge.dashboard.view'],
            ['label' => 'Production Plans', 'route' => 'forge.plans.index', 'permission' => 'forge.plan.view'],
            ['label' => 'Work Orders', 'route' => 'forge.workorders.index', 'permission' => 'forge.workorder.view'],
            ['label' => 'Machines & OEE', 'route' => 'forge.machines.index', 'permission' => 'forge.machine.view'],
            ['label' => 'Wastage & Scrap', 'route' => 'forge.wastage.index', 'permission' => 'forge.wastage.record'],
            ['label' => 'In-Process Quality', 'route' => 'forge.quality-holds.index', 'permission' => 'forge.ipqc.record'],
            ['label' => 'Final QC & FG Release', 'route' => 'forge.final-qc.index', 'permission' => 'forge.finalqc.record'],
            ['label' => 'Deviation & CAPA', 'route' => 'forge.deviations.index', 'permission' => 'forge.deviation.view'],
            ['label' => 'Batch Genealogy', 'route' => 'forge.batches.index', 'permission' => 'forge.batch.trace'],
        ]));
    }

    private function flowItems($user, AccessControlService $access): array
    {
        return array_map(fn ($item) => $item + ['icon' => 'truck'], $this->permitted($user, $access, [
            ['label' => 'Command Centre', 'route' => 'flow.dashboard', 'permission' => 'flow.dashboard.view'],
            ['label' => 'FG Inventory & Ledger', 'route' => 'flow.inventory.index', 'permission' => 'flow.inventory.view'],
            ['label' => 'Customer Orders', 'route' => 'flow.orders.index', 'permission' => 'flow.order.view'],
            ['label' => 'Wave Builder & Picking', 'route' => 'flow.waves.index', 'permission' => 'flow.wave.manage'],
            ['label' => 'Packing', 'route' => 'flow.packing.index', 'permission' => 'flow.pack.manage'],
            ['label' => 'Dispatch', 'route' => 'flow.dispatch.index', 'permission' => 'flow.dispatch.manage'],
            ['label' => 'Delivery & POD', 'route' => 'flow.deliveries.index', 'permission' => 'flow.delivery.manage'],
            ['label' => 'Returns, RMA & Claims', 'route' => 'flow.returns.index', 'permission' => 'flow.return.manage'],
        ]));
    }

    // Keeps items with no 'permission' key, or whose permission the user
    // holds in the Access Control engine; strips the key from the result.
    private function permitted($user, AccessControlService $access, array $items): array
    {
        // A super admin on the advanced engine is allowed everything, and each
        // can() call for them writes an audit row - skip it just to draw a menu.
        $bypass = $user->super_admin && $user->access_mode !== 'legacy';

        $allowed = [];
        foreach ($items as $item) {
            if ($bypass || ! isset($item['permission']) || $access->can($user, $item['permission'])) {
                unset($item['permission']);
                $allowed[] = $item;
            }
        }

        return $allowed;
    }
}; ?>

<aside class="sticky top-0 h-screen w-14 sm:w-64 shrink-0 border-r flex flex-col" style="background: var(--surface-1); border-color: var(--border);">
    <div class="flex items-center justify-center sm:justify-start gap-2.5 px-2 sm:px-5 py-5 border-b" style="border-color: var(--border);">
        <img src="{{ asset('images/tan90-logo.png') }}" alt="Tan90 logo" class="w-10 h-10 rounded-lg object-contain" style="background: var(--surface-1);">
        <span class="hidden sm:inline font-semibold tracking-wide" style="color: var(--text-primary);">Tan90 ERP</span>
    </div>

    <nav class="flex-1 overflow-y-auto py-3 px-2.5 space-y-0.5">
        @foreach ($navItems as $item)
            @if (isset($item['heading']))
                <div class="hidden sm:block px-3 pt-4 pb-1 text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">{{ $item['heading'] }}</div>
                <div class="sm:hidden my-2 border-t" style="border-color: var(--border);"></div>
                @continue
            @endif
            @php
                $active = request()->routeIs($item['route']) && request()->route('entity') == ($item['params'][0] ?? request()->route('entity'));
            @endphp
            <a
                href="{{ route($item['route'], $item['params'] ?? []) }}"
                wire:navigate
                aria-label="{{ $item['label'] }}"
                title="{{ $item['label'] }}"
                @class([
                    'min-h-11 w-full flex items-center justify-center sm:justify-start gap-0 sm:gap-3 px-0 sm:px-3 py-2 rounded-lg text-sm font-medium transition-colors border focus-visible:ring-2 focus-visible:ring-offset-2',
                    'border-transparent' => ! $active,
                ])
                style="{{ $active ? 'background: var(--brand-bg); color: var(--brand); border-color: var(--brand-bg);' : 'color: var(--text-secondary);' }}"
            >
                <x-icon :name="$item['icon'] ?? 'circle'" class="w-5 h-5 shrink-0" />
                <span class="sr-only sm:not-sr-only sm:truncate">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>

    <div class="px-1.5 sm:px-3 py-4 border-t" style="border-color: var(--border);">
        <div class="flex items-center justify-center sm:justify-start gap-2.5 px-0 sm:px-2 mb-2.5">
            <div class="w-8 h-8 shrink-0 rounded-full grid place-items-center text-xs font-bold text-white" style="background: var(--brand);">
                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
            </div>
            <div class="hidden sm:block min-w-0">
                <div class="text-sm font-medium truncate" style="color: var(--text-primary);">{{ auth()->user()->name }}</div>
                <div class="text-xs truncate" style="color: var(--text-muted);">{{ auth()->user()->role?->label() ?? auth()->user()->tan90Profile?->role?->name ?? 'No role assigned' }}</div>
            </div>
        </div>
        <button
            wire:click="logout"
            aria-label="Log out"
            title="Log out"
            class="min-h-11 w-full flex items-center justify-center sm:justify-start gap-0 sm:gap-2.5 px-0 sm:px-3 py-2 rounded-lg text-sm font-medium transition-colors hover:bg-black/5 focus-visible:ring-2 focus-visible:ring-offset-2"
            style="color: var(--status-critical);"
        >
            <x-icon name="log-out" class="w-5 h-5" />
            <span class="sr-only sm:not-sr-only">Log out</span>
        </button>
    </div>
</aside>
