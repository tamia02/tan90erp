<?php

use App\Enums\Role;
use App\Livewire\Forms\LoginForm;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    public function mount(): void
    {
        if ((! app()->isProduction() || filter_var(env('APP_DEMO_MODE', false), FILTER_VALIDATE_BOOL)) && request()->filled('email')) {
            $this->form->email = request()->string('email')->toString();
        }
    }

    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        $home = $this->homeUrl(Auth::user());
        $this->redirectIntended(default: $home, navigate: true);
    }

    public function loginAs(string $role): void
    {
        $role = Role::from($role);

        $user = User::where('role', $role)->firstOrFail();

        Auth::login($user);
        Session::regenerate();

        $this->redirect($this->roleHomeUrl($role), navigate: true);
    }

    private function homeUrl(User $user): string
    {
        if ($user->role) {
            return $this->roleHomeUrl($user->role);
        }

        if (app(AccessControlService::class)->can($user, 'workspace.view')) {
            return rtrim(config('app.url'), '/').route('workspace.index', [], false);
        }

        return rtrim(config('app.url'), '/').route('login', [], false);
    }

    private function roleHomeUrl(Role $role): string
    {
        return rtrim(config('app.url'), '/').route($role->homeRouteName(), [], false);
    }
}; ?>

<div class="tan90-login-shell">
    <section class="tan90-login-hero">
        <div class="tan90-brand-mark">T90</div>
        <p class="tan90-eyebrow">Inward to GRN Control Tower</p>
        <h1>Tan90 ERP</h1>
        <p class="tan90-lead">
            Role-specific portals for gate entry, vendor submission, unloading,
            QC, GRN posting, finance review, and admin control.
        </p>

        <div class="tan90-health-grid">
            <div><strong>4</strong><span>Gate entries</span></div>
            <div><strong>7</strong><span>Active roles</span></div>
            <div><strong>Live</strong><span>Seeded workflow</span></div>
        </div>
    </section>

    <section class="tan90-login-panel">
        <x-auth-session-status class="mb-4" :status="session('status')" />

        <div class="tan90-panel-heading">
            <p>Secure access</p>
            <h2>Open your role workspace</h2>
        </div>

        @if (! app()->isProduction() || filter_var(env('APP_DEMO_MODE', false), FILTER_VALIDATE_BOOL))
            <div class="tan90-role-grid">
                @foreach (\App\Enums\Role::cases() as $role)
                    <a href="{{ route('role-login', $role->value) }}" class="tan90-role-card">
                        <span>{{ $role->label() }}</span>
                        <small>
                            @if ($role->value === 'guard')
                                Gate scan and vehicle inward
                            @elseif ($role->value === 'vendor')
                                Submission and issue response
                            @elseif ($role->value === 'admin')
                                Control tower and masters
                            @else
                                Operations workspace
                            @endif
                        </small>
                    </a>
                @endforeach

                {{-- Master Data / BOM module roles, read from the shared tan90_roles
                     table rather than the enum above — every module seeds into this
                     one table, so a new module's roles show up here automatically
                     once it's merged in, with no change to this view. --}}
                @php
                    $grnCodes = \App\Enums\Role::values();
                    $tan90Roles = \App\Models\Tan90\MasterData\Role::where('status', 'active')
                        ->whereNotIn('code', $grnCodes)
                        ->orderBy('name')
                        ->get();
                @endphp
                @foreach ($tan90Roles as $tan90Role)
                    <a href="{{ route('tan90-role-login', $tan90Role->code) }}" class="tan90-role-card">
                        <span>{{ $tan90Role->name }}</span>
                        <small>{{ $tan90Role->data_scope ?: 'Operations workspace' }}</small>
                    </a>
                @endforeach
            </div>

            <div class="tan90-divider"><span>or sign in manually</span></div>
        @endif

        @if (! app()->isProduction() || filter_var(env('APP_DEMO_MODE', false), FILTER_VALIDATE_BOOL))
            <div class="tan90-demo-access">
                <p>Explore Demo Accounts by Hierarchy</p>
                @php
                    $demoGroups = [
                        'Level 1 / Super Admin' => ['superadmin@tan90.demo'],
                        'Level 2 / Heads of Vertical' => ['head.store@tan90.demo', 'head.quality@tan90.demo', 'head.procurement@tan90.demo', 'head.finance@tan90.demo', 'head.masterdata@tan90.demo'],
                        'Level 3 / Managers' => ['manager.grn@tan90.demo', 'manager.qc@tan90.demo', 'manager.vendor@tan90.demo', 'manager.finance@tan90.demo', 'manager.masterdata@tan90.demo'],
                        'Level 4 / Executives and Employees' => ['executive.grn@tan90.demo', 'executive.qc@tan90.demo', 'executive.grnqc@tan90.demo', 'executive.vendor@tan90.demo', 'executive.readonly@tan90.demo', 'executive.finance@tan90.demo', 'executive.masterdata@tan90.demo'],
                    ];
                    $demoUsers = \App\Models\User::whereIn('email', collect($demoGroups)->flatten())
                        ->with(['accessRoles', 'accessPositions.vertical', 'accessPositions.unit', 'accessPositions.team', 'accessPositions.manager'])
                        ->get()
                        ->keyBy('email');
                @endphp
                @foreach ($demoGroups as $group => $emails)
                    <details @open($loop->first)>
                        <summary>{{ $group }}</summary>
                        <div>
                            @foreach ($emails as $email)
                                @if ($demoUsers->has($email))
                                    @php($demoUser = $demoUsers[$email])
                                    <form method="post" action="{{ route('demo-login', $demoUser) }}">
                                        @csrf
                                        <button type="submit">
                                            <strong>{{ $demoUser->name }}</strong>
                                            <span>{{ $email }}</span>
                                            <small>{{ $demoUser->accessRoles->pluck('name')->implode(', ') ?: 'Advanced access' }}</small>
                                            @if($demoUser->accessPositions->first())
                                                @php($position = $demoUser->accessPositions->first())
                                                <small>{{ $position->vertical?->name ?? 'All verticals' }} / {{ $position->team?->name ?? 'All teams' }} / Reports to {{ $position->manager?->name ?? 'top level' }}</small>
                                            @endif
                                        </button>
                                    </form>
                                @endif
                            @endforeach
                        </div>
                    </details>
                @endforeach
                <small>Manual password for all demo accounts: demo123</small>
            </div>
        @endif

        <form wire:submit="login" class="tan90-manual-form">
            <div>
                <x-input-label for="email" :value="__('Email')" />
                <x-text-input wire:model="form.email" id="email" class="block mt-2 w-full" type="email" name="email" required autofocus autocomplete="username" />
                <x-input-error :messages="$errors->get('form.email')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="password" :value="__('Password')" />
                <x-text-input wire:model="form.password" id="password" class="block mt-2 w-full" type="password" name="password" required autocomplete="current-password" />
                <x-input-error :messages="$errors->get('form.password')" class="mt-2" />
            </div>

            <div class="tan90-form-actions">
                <label for="remember" class="inline-flex items-center">
                    <input wire:model="form.remember" id="remember" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" name="remember">
                    <span class="ms-2 text-sm text-slate-600">{{ __('Remember me') }}</span>
                </label>

                <x-primary-button>
                    {{ __('Log in') }}
                </x-primary-button>
            </div>
        </form>
    </section>
</div>
