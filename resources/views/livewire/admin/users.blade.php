<?php

use App\Enums\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\SlaDirectives;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public bool $adding = false;

    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $role = 'guard';
    public string $vendorTier = 'basic';
    public string $description = '';
    public string $slaDirective = '';
    public bool $superAdmin = false;
    public string $password = '';

    public function addUser(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['required', Rule::enum(Role::class)],
            'vendorTier' => ['nullable', 'in:basic,advanced'],
            'description' => ['nullable', 'string', 'max:255'],
            'slaDirective' => ['nullable', Rule::in(array_keys(SlaDirectives::OPTIONS))],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?: null,
            'role' => $validated['role'],
            'vendor_tier' => $validated['role'] === Role::Vendor->value ? $validated['vendorTier'] : null,
            'description' => $validated['description'] ?: null,
            'sla_directive' => $validated['slaDirective'] ?: null,
            'super_admin' => $this->superAdmin,
            'password' => Hash::make($validated['password']),
        ]);

        AuditLogger::log('User added'.($user->super_admin ? ' (Super Admin)' : ''), "{$user->name} · {$user->role->label()}", $user);

        $this->reset(['name', 'email', 'phone', 'role', 'vendorTier', 'description', 'slaDirective', 'superAdmin', 'password', 'adding']);
        $this->role = 'guard';
        $this->vendorTier = 'basic';
    }

    public function deleteUser(int $id): void
    {
        $user = User::findOrFail($id);
        AuditLogger::log('User removed', "{$user->name} · {$user->role->label()}");
        $user->delete();
    }

    public function with(): array
    {
        return [
            'users' => User::orderByDesc('created_at')->get(),
            'roles' => Role::cases(),
            'slaOptions' => SlaDirectives::OPTIONS,
        ];
    }
}; ?>

<div class="max-w-5xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
        <div>
            <h1 class="text-xl sm:text-2xl font-semibold" style="color: var(--text-primary);">User Management</h1>
            <p class="text-sm mt-1" style="color: var(--text-secondary);">Every account and the role it's assigned — admin creates every login, no self-registration.</p>
        </div>
        <button
            wire:click="$toggle('adding')"
            class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium border"
            style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);"
        >
            {{ $adding ? 'Cancel' : 'Add user' }}
        </button>
    </div>


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

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="px-4 py-2.5 font-medium">Name</th>
                        <th class="px-4 py-2.5 font-medium">Email</th>
                        <th class="px-4 py-2.5 font-medium">Phone</th>
                        <th class="px-4 py-2.5 font-medium">Role</th>
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
                            <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $user->role->label() }}</td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);">{{ $user->role === \App\Enums\Role::Vendor ? ucfirst($user->vendor_tier ?? 'basic') : '—' }}</td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);">{{ \App\Support\SlaDirectives::label($user->sla_directive) }}</td>
                            <td class="px-4 py-2.5">{{ $user->super_admin ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2.5 text-right">
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
                    @empty
                        <tr><td colspan="8" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No users yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
