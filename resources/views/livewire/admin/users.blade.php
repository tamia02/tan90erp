<?php

use App\Enums\Role;
use App\Models\Access\AccessPermission;
use App\Models\Access\AccessPosition;
use App\Models\Access\AccessRole;
use App\Models\Access\AccessUserPermissionOverride;
use App\Models\Tan90\MasterData\Location;
use App\Models\User;
use App\Services\Access\AccessControlService;
use App\Services\Access\SuperAdminAccessSync;
use App\Services\AuditLogger;
use App\Support\SlaDirectives;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public bool $adding = false;

    public ?int $editingUserId = null;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $role = 'guard';

    public string $locationId = '';

    public string $vendorTier = 'basic';

    public string $description = '';

    public string $slaDirective = '';

    public bool $superAdmin = false;

    public string $password = '';

    public string $accessRoleId = '';

    public string $reportsToUserId = '';

    public array $reportUserIds = [];

    public ?int $accessManagerUserId = null;

    public string $accessRoleToAssign = '';

    public string $accessManagerReportsToUserId = '';

    public array $accessManagerTeamUserIds = [];

    public function addUser(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['required', Rule::enum(Role::class)],
            'locationId' => ['nullable', 'integer', Rule::exists('tan90_locations', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'vendorTier' => ['nullable', 'in:basic,advanced'],
            'description' => ['nullable', 'string', 'max:255'],
            'slaDirective' => ['nullable', Rule::in(array_keys(SlaDirectives::OPTIONS))],
            'password' => ['required', 'string', 'min:8'],
            'accessRoleId' => ['nullable', Rule::exists('access_roles', 'id')->where('status', 'active')],
            'reportsToUserId' => ['nullable', 'exists:users,id'],
            'reportUserIds' => ['nullable', 'array'],
            'reportUserIds.*' => ['integer', 'exists:users,id'],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $accessRole = filled($validated['accessRoleId'] ?? null) ? AccessRole::findOrFail($validated['accessRoleId']) : null;
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?: null,
                'role' => $validated['role'],
                'location_id' => $validated['locationId'] ?: null,
                'vendor_tier' => $validated['role'] === Role::Vendor->value ? $validated['vendorTier'] : null,
                'description' => $validated['description'] ?: null,
                'sla_directive' => $validated['slaDirective'] ?: null,
                'super_admin' => $this->superAdmin,
                'access_mode' => $accessRole ? 'advanced' : 'legacy',
                'is_active' => true,
                'password' => Hash::make($validated['password']),
            ]);

            if ($accessRole) {
                DB::table('access_user_roles')->insert([
                    'user_id' => $user->id, 'role_id' => $accessRole->id, 'assigned_by' => auth()->id(),
                    'starts_at' => now(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            if ($accessRole || filled($validated['reportsToUserId'] ?? null)) {
                AccessPosition::create([
                    'user_id' => $user->id,
                    'hierarchy_level' => $accessRole?->hierarchy_level ?? $accessRole?->level ?? AccessRole::LEVEL_EXECUTIVE,
                    'vertical_id' => $accessRole?->vertical_id,
                    'reports_to_user_id' => $validated['reportsToUserId'] ?: null,
                    'is_primary' => true,
                    'status' => 'active',
                    'starts_at' => now(),
                    'created_by' => auth()->id(),
                ]);
            }

            $reportIds = collect($validated['reportUserIds'] ?? [])->unique()->reject(fn ($id) => (int) $id === $user->id);
            $reportIds->each(function ($reportId) use ($user) {
                AccessPosition::where('user_id', $reportId)->where('is_primary', true)->update(['is_primary' => false]);
                $existing = AccessPosition::where('user_id', $reportId)->latest('id')->first();
                AccessPosition::create([
                    'user_id' => $reportId,
                    'hierarchy_level' => $existing->hierarchy_level ?? AccessRole::LEVEL_EXECUTIVE,
                    'vertical_id' => $existing->vertical_id,
                    'unit_id' => $existing->unit_id,
                    'team_id' => $existing->team_id,
                    'reports_to_user_id' => $user->id,
                    'is_primary' => true,
                    'status' => 'active',
                    'starts_at' => now(),
                    'created_by' => auth()->id(),
                ]);
            });

            if ($reportIds->isNotEmpty()) {
                $access = app(AccessControlService::class);
                $widgetKeys = $reportIds->map(fn ($id) => User::find($id))->filter()
                    ->flatMap(fn (User $report) => $access->widgetsFor($report)->pluck('permission_key'))
                    ->push('workspace.view')->unique();
                foreach ($widgetKeys as $key) {
                    $permission = AccessPermission::where('key', $key)->first();
                    if ($permission) {
                        AccessUserPermissionOverride::updateOrCreate(
                            ['user_id' => $user->id, 'permission_id' => $permission->id],
                            ['granted_by' => auth()->id(), 'allowed' => true, 'effect' => 'allow', 'scope_type' => 'all', 'starts_at' => now(), 'status' => 'active', 'reason' => 'Auto-granted: combined dashboard for assigned team']
                        );
                    }
                }
            }

            return $user;
        });

        if ($user->super_admin && $user->role === Role::Admin) {
            app(SuperAdminAccessSync::class)->syncUser($user->id);
        }

        $access = app(AccessControlService::class);
        $access->flushUser($user);
        foreach ($validated['reportUserIds'] ?? [] as $reportId) {
            $access->flushUser(User::find($reportId));
        }
        AuditLogger::log('User added'.($user->super_admin ? ' (Super Admin)' : ''), "{$user->name} · {$user->role->label()}", $user);

        $this->reset(['name', 'email', 'phone', 'role', 'locationId', 'vendorTier', 'description', 'slaDirective', 'superAdmin', 'password', 'accessRoleId', 'reportsToUserId', 'reportUserIds', 'adding']);
        $this->role = 'guard';
        $this->vendorTier = 'basic';
    }

    public function startEditing(int $id): void
    {
        $user = User::findOrFail($id);

        $this->adding = false;
        $this->editingUserId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->phone = $user->phone ?? '';
        $this->role = $user->role?->value ?? '';
        $this->locationId = (string) ($user->location_id ?? '');
        $this->vendorTier = $user->vendor_tier ?? 'basic';
        $this->description = $user->description ?? '';
        $this->slaDirective = $user->sla_directive ?? '';
        $this->superAdmin = (bool) $user->super_admin;
        $this->resetValidation();
    }

    public function cancelEditing(): void
    {
        $this->editingUserId = null;
        $this->reset(['name', 'email', 'phone', 'locationId', 'description', 'slaDirective', 'superAdmin']);
        $this->role = Role::Guard->value;
        $this->vendorTier = 'basic';
        $this->resetValidation();
    }

    public function updateUser(): void
    {
        $user = User::findOrFail($this->editingUserId);
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['nullable', Rule::enum(Role::class)],
            'locationId' => ['nullable', 'integer', Rule::exists('tan90_locations', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'vendorTier' => ['nullable', 'in:basic,advanced'],
            'description' => ['nullable', 'string', 'max:255'],
            'slaDirective' => ['nullable', Rule::in(array_keys(SlaDirectives::OPTIONS))],
        ]);

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?: null,
            'role' => $validated['role'],
            'location_id' => $validated['locationId'] ?: null,
            'vendor_tier' => $validated['role'] === Role::Vendor->value ? $validated['vendorTier'] : null,
            'description' => $validated['description'] ?: null,
            'sla_directive' => $validated['slaDirective'] ?: null,
            'super_admin' => $this->superAdmin,
        ])->save();

        if ($user->super_admin && $user->role === Role::Admin) {
            app(SuperAdminAccessSync::class)->syncUser($user->id);
        }

        AuditLogger::log('User updated'.($user->super_admin ? ' (Super Admin)' : ''), $user->name.' · '.implode(', ', array_keys($user->getChanges())), $user);
        $this->cancelEditing();
    }

    public function deleteUser(int $id): void
    {
        $user = User::findOrFail($id);
        AuditLogger::log('User removed', "{$user->name} · ".($user->role?->label() ?? 'Access Control only'));
        $user->delete();
    }

    public function openAccessManager(int $userId): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        if ($this->accessManagerUserId === $userId) {
            $this->accessManagerUserId = null;

            return;
        }

        $user = User::findOrFail($userId);
        $position = $user->accessPositions()->where('is_primary', true)->where('status', 'active')->latest('id')->first();
        $this->accessManagerUserId = $user->id;
        $this->accessRoleToAssign = '';
        $this->accessManagerReportsToUserId = (string) ($position?->reports_to_user_id ?? '');
        $this->accessManagerTeamUserIds = AccessPosition::where('reports_to_user_id', $user->id)
            ->where('is_primary', true)->where('status', 'active')->pluck('user_id')->map(fn ($id) => (string) $id)->all();
        $this->resetValidation();
    }

    public function assignAccessRole(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $validated = $this->validate([
            'accessManagerUserId' => ['required', 'exists:users,id'],
            'accessRoleToAssign' => ['required', Rule::exists('access_roles', 'id')->where('status', 'active')],
        ]);
        $user = User::findOrFail($validated['accessManagerUserId']);
        $role = AccessRole::findOrFail($validated['accessRoleToAssign']);

        DB::transaction(function () use ($user, $role) {
            DB::table('access_user_roles')->updateOrInsert(
                ['user_id' => $user->id, 'role_id' => $role->id],
                ['assigned_by' => auth()->id(), 'starts_at' => now(), 'expires_at' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
            );
            if ($user->access_mode !== 'advanced') {
                $user->forceFill(['access_mode' => 'advanced'])->save();
            }
            if (! $user->accessPositions()->where('is_primary', true)->where('status', 'active')->exists()) {
                AccessPosition::create([
                    'user_id' => $user->id,
                    'hierarchy_level' => $role->hierarchy_level ?? $role->level,
                    'vertical_id' => $role->vertical_id,
                    'is_primary' => true,
                    'status' => 'active',
                    'starts_at' => now(),
                    'created_by' => auth()->id(),
                ]);
            }
        });

        app(AccessControlService::class)->flushUser($user);
        app(AccessControlService::class)->audit(auth()->user(), 'user.role.assigned', $user, null, ['role_id' => $role->id]);
        $this->accessRoleToAssign = '';
    }

    public function removeAccessRole(int $roleId): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $user = User::findOrFail($this->accessManagerUserId);
        $role = AccessRole::findOrFail($roleId);
        abort_if(auth()->user()->is($user) && $role->code === 'ACCESS-SUPER-ADMIN', 422, 'You cannot remove your own Super Admin role.');

        DB::table('access_user_roles')->where('user_id', $user->id)->where('role_id', $role->id)->delete();
        DB::table('access_user_role_assignments')->where('user_id', $user->id)->where('role_id', $role->id)->delete();
        app(AccessControlService::class)->flushUser($user);
        app(AccessControlService::class)->audit(auth()->user(), 'user.role.removed', $user, ['role_id' => $role->id], null);
    }

    public function saveReporting(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $validated = $this->validate([
            'accessManagerUserId' => ['required', 'exists:users,id'],
            'accessManagerReportsToUserId' => ['nullable', 'exists:users,id'],
            'accessManagerTeamUserIds' => ['nullable', 'array'],
            'accessManagerTeamUserIds.*' => ['integer', 'exists:users,id'],
        ]);
        $user = User::findOrFail($validated['accessManagerUserId']);
        $reportIds = collect($validated['accessManagerTeamUserIds'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        if ($reportIds->contains($user->id) || (int) ($validated['accessManagerReportsToUserId'] ?? 0) === $user->id) {
            $this->addError('accessManagerUserId', 'A user cannot report to themselves or be their own team member.');

            return;
        }
        $before = [
            'reports_to_user_id' => $user->accessPositions()->where('is_primary', true)->where('status', 'active')->latest('id')->value('reports_to_user_id'),
            'report_user_ids' => AccessPosition::where('reports_to_user_id', $user->id)->where('is_primary', true)->where('status', 'active')->pluck('user_id')->all(),
        ];

        DB::transaction(function () use ($user, $reportIds, $validated) {
            $position = $user->accessPositions()->where('is_primary', true)->where('status', 'active')->latest('id')->first();
            if (! $position) {
                $role = $user->accessRoles()->wherePivot('status', 'active')->first();
                $position = AccessPosition::make([
                    'user_id' => $user->id,
                    'hierarchy_level' => $role?->hierarchy_level ?? $role?->level ?? AccessRole::LEVEL_EXECUTIVE,
                    'vertical_id' => $role?->vertical_id,
                    'is_primary' => true,
                    'status' => 'active',
                    'starts_at' => now(),
                    'created_by' => auth()->id(),
                ]);
            }
            $position->reports_to_user_id = $validated['accessManagerReportsToUserId'] ?: null;
            $position->save();

            AccessPosition::where('reports_to_user_id', $user->id)
                ->where('is_primary', true)->where('status', 'active')
                ->whereNotIn('user_id', $reportIds->isEmpty() ? [-1] : $reportIds->all())
                ->update(['is_primary' => false, 'ends_at' => now(), 'status' => 'inactive']);

            foreach ($reportIds as $reportId) {
                $current = AccessPosition::where('user_id', $reportId)->where('is_primary', true)->where('status', 'active')->latest('id')->first();
                if ($current?->reports_to_user_id === $user->id) {
                    continue;
                }
                AccessPosition::where('user_id', $reportId)->where('is_primary', true)->where('status', 'active')->update(['is_primary' => false, 'ends_at' => now()]);
                AccessPosition::create([
                    'user_id' => $reportId,
                    'hierarchy_level' => $current?->hierarchy_level ?? AccessRole::LEVEL_EXECUTIVE,
                    'vertical_id' => $current?->vertical_id,
                    'unit_id' => $current?->unit_id,
                    'team_id' => $current?->team_id,
                    'reports_to_user_id' => $user->id,
                    'is_primary' => true,
                    'status' => 'active',
                    'starts_at' => now(),
                    'created_by' => auth()->id(),
                ]);
            }

            if ($reportIds->isNotEmpty()) {
                $access = app(AccessControlService::class);
                $widgetKeys = $reportIds->map(fn ($id) => User::find($id))->filter()
                    ->flatMap(fn (User $report) => $access->widgetsFor($report)->pluck('permission_key'))
                    ->push('workspace.view')->unique();
                foreach ($widgetKeys as $key) {
                    $permission = AccessPermission::where('key', $key)->first();
                    if ($permission) {
                        AccessUserPermissionOverride::updateOrCreate(
                            ['user_id' => $user->id, 'permission_id' => $permission->id],
                            ['granted_by' => auth()->id(), 'allowed' => true, 'effect' => 'allow', 'scope_type' => 'all', 'starts_at' => now(), 'status' => 'active', 'reason' => 'Auto-granted: combined dashboard for assigned team']
                        );
                    }
                }
            }
        });

        $access = app(AccessControlService::class);
        $access->flushUser($user);
        foreach ($before['report_user_ids'] as $id) {
            $access->flushUser(User::find($id));
        }
        foreach ($reportIds as $id) {
            $access->flushUser(User::find($id));
        }
        $access->audit(auth()->user(), 'user.reporting.updated', $user, $before, [
            'reports_to_user_id' => $validated['accessManagerReportsToUserId'] ?: null,
            'report_user_ids' => $reportIds->all(),
        ]);
    }

    public function with(): array
    {
        return [
            'users' => User::with(['location', 'accessRoles', 'accessPositions.manager'])->orderByDesc('created_at')->get(),
            'accessRoles' => AccessRole::with('vertical')->where('status', 'active')->orderBy('level')->orderBy('name')->get(),
            'peopleOptions' => User::orderBy('name')->get(['id', 'name', 'email']),
            'roles' => Role::cases(),
            'slaOptions' => SlaDirectives::OPTIONS,
            'siteLocations' => Location::query()->where('status', 'active')->whereIn('type', ['Manufacturing', 'Warehouse'])->with(['plants', 'warehouses'])->orderBy('name')->get(),
        ];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Admin Module</div>
                <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">User Management</h1>
                <p class="text-sm mt-1" style="color: var(--text-secondary);">Every account and the role it's assigned — admin creates every login, no self-registration.</p>
            </div>
            <button
                wire:click="$toggle('adding')"
                class="inline-flex items-center justify-center gap-1.5 rounded-xl px-3.5 py-2.5 text-sm font-semibold border shrink-0"
                style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);"
            >
                {{ $adding ? 'Cancel' : 'Add user' }}
            </button>
        </div>
    </section>


    @if ($adding)
        <div class="rounded-lg border p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3" style="background: var(--surface-3); border-color: var(--border);">
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Full name</span>
                <input wire:model="name" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                @error('name') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Email</span>
                <input wire:model="email" type="email" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                @error('email') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Phone</span>
                <input wire:model="phone" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Role</span>
                <select wire:model.live="role" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    @foreach ($roles as $r)
                        <option value="{{ $r->value }}">{{ $r->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Access role (optional)</span>
                <select wire:model="accessRoleId" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    <option value="">No extra access role</option>
                    @foreach ($accessRoles as $accessRole)
                        <option value="{{ $accessRole->id }}">{{ $accessRole->name }}{{ $accessRole->vertical ? ' · '.$accessRole->vertical->name : '' }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Reports to (optional)</span>
                <select wire:model="reportsToUserId" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    <option value="">No reporting manager</option>
                    @foreach ($peopleOptions as $person)
                        <option value="{{ $person->id }}">{{ $person->name }} · {{ $person->email }}</option>
                    @endforeach
                </select>
            </label>
            @if ($peopleOptions->isNotEmpty())
                <details class="text-sm sm:col-span-2 rounded-lg border p-3" style="border-color: var(--border);">
                    <summary class="cursor-pointer font-medium" style="color: var(--text-primary);">Assign existing team members to this person</summary>
                    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-2 mt-3 max-h-56 overflow-y-auto">
                        @foreach ($peopleOptions as $person)
                            <label class="flex items-center gap-2 rounded-lg border p-2" style="border-color: var(--border);">
                                <input type="checkbox" wire:model="reportUserIds" value="{{ $person->id }}">
                                <span>{{ $person->name }} <small style="color: var(--text-muted);">{{ $person->email }}</small></span>
                            </label>
                        @endforeach
                    </div>
                </details>
            @endif
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Location (site)</span>
                <select wire:model="locationId" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    <option value="">All sites</option>
                    @foreach ($siteLocations as $site)
                        <option value="{{ $site->id }}">{{ $site->name }} ({{ $site->city }}) — Plant: {{ $site->plants->pluck('name')->implode(', ') ?: 'None' }} · Active warehouse: {{ $site->warehouses->where('status', 'active')->map(fn ($warehouse) => $warehouse->code.' — '.$warehouse->name)->implode(', ') ?: 'None' }}</option>
                    @endforeach
                </select>
                <span class="text-xs" style="color: var(--text-muted);">Inventory access follows this site and includes its linked active warehouses.</span>
                @error('locationId') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            @if ($role === 'vendor')
                <label class="flex flex-col gap-1.5 text-sm">
                    <span class="font-medium" style="color: var(--text-primary);">Vendor UI</span>
                    <select wire:model="vendorTier" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                        <option value="basic">Small Business (simple view)</option>
                        <option value="advanced">Advanced (detailed dashboard)</option>
                    </select>
                </label>
            @endif
            <label class="flex flex-col gap-1.5 text-sm sm:col-span-2">
                <span class="font-medium" style="color: var(--text-primary);">Description</span>
                <input wire:model="description" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Gate 1, day shift" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">SLA directive</span>
                <select wire:model="slaDirective" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    <option value="">Not set</option>
                    @foreach ($slaOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Password</span>
                <input wire:model="password" type="password" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                @error('password') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex items-center gap-2 text-sm sm:col-span-2">
                <input wire:model="superAdmin" type="checkbox" class="w-4 h-4" />
                <span style="color: var(--text-primary);">Super Admin</span>
            </label>
            <button
                wire:click="addUser"
                class="sm:col-span-2 rounded-lg px-3.5 py-2 text-sm font-medium text-white"
                style="background: var(--brand);"
            >
                Add to team
            </button>
        </div>
    @endif

    @if ($editingUserId)
        <div class="rounded-lg border p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3" style="background: var(--surface-3); border-color: var(--border);">
            <div class="sm:col-span-2 flex items-center justify-between">
                <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Edit user</h2>
                <button type="button" wire:click="cancelEditing" class="text-sm font-medium" style="color: var(--brand);">Cancel</button>
            </div>
            <label class="flex flex-col gap-1.5 text-sm"><span class="font-medium" style="color: var(--text-primary);">Full name</span><input wire:model="name" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />@error('name') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror</label>
            <label class="flex flex-col gap-1.5 text-sm"><span class="font-medium" style="color: var(--text-primary);">Email</span><input wire:model="email" type="email" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />@error('email') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror</label>
            <label class="flex flex-col gap-1.5 text-sm"><span class="font-medium" style="color: var(--text-primary);">Phone</span><input wire:model="phone" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" /></label>
            <label class="flex flex-col gap-1.5 text-sm"><span class="font-medium" style="color: var(--text-primary);">Role</span><select wire:model.live="role" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);"><option value="">Access Control only</option>@foreach ($roles as $r)<option value="{{ $r->value }}">{{ $r->label() }}</option>@endforeach</select></label>
            <label class="flex flex-col gap-1.5 text-sm"><span class="font-medium" style="color: var(--text-primary);">Location / warehouse access</span><select wire:model="locationId" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);"><option value="">All sites</option>@foreach ($siteLocations as $site)<option value="{{ $site->id }}">{{ $site->name }} ({{ $site->city }}) — Plant: {{ $site->plants->pluck('name')->implode(', ') ?: 'None' }} · Active warehouse: {{ $site->warehouses->where('status', 'active')->map(fn ($warehouse) => $warehouse->code.' — '.$warehouse->name)->implode(', ') ?: 'None' }}</option>@endforeach</select><span class="text-xs" style="color: var(--text-muted);">For now, each site maps to its active warehouse. Inventory access follows that warehouse.</span>@error('locationId') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror</label>
            @if ($role === 'vendor')<label class="flex flex-col gap-1.5 text-sm"><span class="font-medium" style="color: var(--text-primary);">Vendor UI</span><select wire:model="vendorTier" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);"><option value="basic">Small Business (simple view)</option><option value="advanced">Advanced (detailed dashboard)</option></select></label>@endif
            <label class="flex flex-col gap-1.5 text-sm"><span class="font-medium" style="color: var(--text-primary);">Description</span><input wire:model="description" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" /></label>
            <label class="flex flex-col gap-1.5 text-sm"><span class="font-medium" style="color: var(--text-primary);">SLA directive</span><select wire:model="slaDirective" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);"><option value="">Not set</option>@foreach ($slaOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            <p class="sm:col-span-2 text-xs" style="color: var(--text-secondary);">The account’s current Super Admin setting is preserved while you edit its other details.</p>
            <button type="button" wire:click="updateUser" class="sm:col-span-2 rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Save user changes</button>
        </div>
    @endif

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="px-4 py-2.5 font-medium">Name</th>
                        <th class="px-4 py-2.5 font-medium">Email</th>
                        <th class="px-4 py-2.5 font-medium">Phone</th>
                        <th class="px-4 py-2.5 font-medium">Role</th>
                        <th class="px-4 py-2.5 font-medium">Access roles</th>
                        <th class="px-4 py-2.5 font-medium">Reports to</th>
                        <th class="px-4 py-2.5 font-medium">Location</th>
                        <th class="px-4 py-2.5 font-medium">Vendor UI</th>
                        <th class="px-4 py-2.5 font-medium">SLA Directive</th>
                        <th class="px-4 py-2.5 font-medium">Super Admin</th>
                        <th class="px-4 py-2.5 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">{{ $user->name }}</td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);">{{ $user->email }}</td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);">{{ $user->phone ?? '—' }}</td>
                            <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $user->role?->label() ?? 'Access Control only' }}</td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);">{{ $user->accessRoles->pluck('name')->implode(', ') ?: '—' }}</td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);">{{ $user->accessPositions->firstWhere('is_primary', true)?->manager?->name ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);">{{ $user->location?->name ?? 'All sites' }}</td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);">{{ $user->role === \App\Enums\Role::Vendor ? ucfirst($user->vendor_tier ?? 'basic') : '—' }}</td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);">{{ \App\Support\SlaDirectives::label($user->sla_directive) }}</td>
                            <td class="px-4 py-2.5">{{ $user->super_admin ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <button type="button" wire:click="openAccessManager({{ $user->id }})" class="p-1.5 rounded-lg mr-2" style="color: var(--brand);">{{ $accessManagerUserId === $user->id ? 'Close access' : 'Manage access & team' }}</button>
                                <button
                                    wire:click="startEditing({{ $user->id }})"
                                    class="p-1.5 rounded-lg mr-2"
                                    style="color: var(--brand);"
                                >
                                    Edit
                                </button>
                                <button
                                    wire:click="deleteUser({{ $user->id }})"
                                    wire:confirm="Remove {{ $user->name }}? They'll lose access immediately."
                                    class="p-1.5 rounded-lg"
                                    style="color: var(--status-critical);"
                                >
                                    Remove
                                </button>
                            </td>
                        </tr>
                        @if ($accessManagerUserId === $user->id)
                            <tr style="border-top: 1px solid var(--border);">
                                <td colspan="11" class="p-4">
                                    <div class="grid lg:grid-cols-2 gap-4 text-sm">
                                        <section class="rounded-xl border p-4 space-y-3" style="border-color: var(--border);">
                                            <h3 class="font-semibold" style="color: var(--text-primary);">Access roles for {{ $user->name }}</h3>
                                            <div class="flex flex-wrap gap-2">
                                                @forelse ($user->accessRoles as $assignedRole)
                                                    <span class="inline-flex items-center gap-2 rounded-full border px-3 py-1" style="border-color: var(--border);">
                                                        {{ $assignedRole->name }}
                                                        <button type="button" wire:click="removeAccessRole({{ $assignedRole->id }})" class="font-semibold" aria-label="Remove {{ $assignedRole->name }}">×</button>
                                                    </span>
                                                @empty
                                                    <span style="color: var(--text-muted);">No Access Control role assigned.</span>
                                                @endforelse
                                            </div>
                                            <div class="flex flex-col sm:flex-row gap-2">
                                                <select wire:model="accessRoleToAssign" class="rounded-lg border px-3 py-2" style="border-color: var(--border);">
                                                    <option value="">Select an active access role</option>
                                                    @foreach ($accessRoles as $accessRole)
                                                        <option value="{{ $accessRole->id }}">{{ $accessRole->name }}</option>
                                                    @endforeach
                                                </select>
                                                <button type="button" wire:click="assignAccessRole" class="rounded-lg px-3 py-2 text-white" style="background: var(--brand);">Assign role</button>
                                            </div>
                                            @error('accessRoleToAssign')<p class="text-xs" style="color: var(--status-critical);">{{ $message }}</p>@enderror
                                        </section>
                                        <section class="rounded-xl border p-4 space-y-3" style="border-color: var(--border);">
                                            <h3 class="font-semibold" style="color: var(--text-primary);">Reporting and team</h3>
                                            <label class="block"><span class="block mb-1">Reports to</span>
                                                <select wire:model="accessManagerReportsToUserId" class="rounded-lg border px-3 py-2 w-full" style="border-color: var(--border);">
                                                    <option value="">No reporting manager</option>
                                                    @foreach ($peopleOptions->where('id', '!=', $user->id) as $person)
                                                        <option value="{{ $person->id }}">{{ $person->name }} · {{ $person->email }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <details>
                                                <summary class="cursor-pointer font-medium">People reporting to {{ $user->name }}</summary>
                                                <div class="grid sm:grid-cols-2 gap-2 mt-2 max-h-48 overflow-y-auto">
                                                    @foreach ($peopleOptions->where('id', '!=', $user->id) as $person)
                                                        <label class="flex items-center gap-2 rounded-lg border p-2" style="border-color: var(--border);">
                                                            <input type="checkbox" wire:model="accessManagerTeamUserIds" value="{{ $person->id }}">
                                                            <span>{{ $person->name }} <small style="color: var(--text-muted);">{{ $person->email }}</small></span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                            </details>
                                            @error('accessManagerUserId')<p class="text-xs" style="color: var(--status-critical);">{{ $message }}</p>@enderror
                                            <button type="button" wire:click="saveReporting" class="rounded-lg px-3 py-2 text-white" style="background: var(--brand);">Save reporting &amp; team</button>
                                        </section>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="11" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No users yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
