<?php

use App\Enums\Role;
use App\Livewire\Forms\LoginForm;
use App\Models\User;
use App\Services\Auth\EmailLoginCodeService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    public string $loginMode = 'password';

    public string $emailCode = '';

    public bool $codeRequested = false;

    public function mount(): void
    {
        if (request()->filled('email')) {
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

    public function usePasswordLogin(): void
    {
        $this->loginMode = 'password';
        $this->emailCode = '';
        $this->codeRequested = false;
        $this->resetErrorBag();
    }

    public function useEmailCodeLogin(): void
    {
        $this->loginMode = 'email-code';
        $this->form->password = '';
        $this->resetErrorBag();
    }

    public function updatedFormEmail(): void
    {
        $this->emailCode = '';
        $this->codeRequested = false;
    }

    public function sendEmailCode(): void
    {
        $this->validate(['form.email' => ['required', 'string', 'email', 'max:254']]);

        app(EmailLoginCodeService::class)->send($this->form->email);

        $this->codeRequested = true;
        $this->emailCode = '';
        $this->resetErrorBag('emailCode');
        $this->resetErrorBag('form.email');
        session()->flash('status', 'If an active Tan90 account uses this email, a sign-in code has been sent.');
    }

    public function loginWithEmailCode(): void
    {
        $this->validate([
            'form.email' => ['required', 'string', 'email', 'max:254'],
            'emailCode' => ['required', 'string', 'digits:6'],
        ]);

        $user = app(EmailLoginCodeService::class)->verify($this->form->email, $this->emailCode);

        Auth::login($user, $this->form->remember);
        Session::regenerate();

        $this->redirectIntended(default: $this->homeUrl($user), navigate: true);
    }

    public function loginAs(string $role): void
    {
        abort_unless(config('app.demo_logins') && app()->environment(['local', 'staging', 'testing']), 404);

        $role = Role::from($role);
        $user = User::query()
            ->where('role', $role->value)
            ->whereIn('email', config('app.demo_login_emails', []))
            ->where('is_active', true)
            ->firstOrFail();

        Auth::login($user);
        Session::regenerate();

        $this->redirect($this->roleHomeUrl($role), navigate: true);
    }

    private function homeUrl(User $user): string
    {
        return rtrim(config('app.url'), '/').route($user->homeRouteName(), [], false);
    }

    private function roleHomeUrl(Role $role): string
    {
        return rtrim(config('app.url'), '/').route($role->homeRouteName(), [], false);
    }
}; ?>

<div class="tan90-login-shell">
    <section class="tan90-login-hero">
        <a class="tan90-login-brand" href="{{ route('login') }}" aria-label="Tan90 ERP home">
            <img src="{{ asset('brand/tan90-logo.webp') }}" alt="Tan90" width="112" height="112">
            <span>ERP <small>ONE CONNECTED WORKSPACE</small></span>
        </a>
        <div class="tan90-hero-copy">
            <p class="tan90-eyebrow">PEOPLE | INVENTORY | OPERATIONS</p>
            <h1>Connected through<br><span>ONE Workspace</span></h1>
        </div>
        <div class="tan90-penguin-scene">
            <span class="tan90-orbit tan90-orbit-one" aria-hidden="true"></span>
            <span class="tan90-orbit tan90-orbit-two" aria-hidden="true"></span>
            <img class="tan90-penguin" src="{{ asset('brand/tan90-penguin.jpeg') }}" alt="A cheerful penguin in a winter hat and scarf" width="187" height="274" fetchpriority="high">
            <span class="tan90-scene-note"><i aria-hidden="true"></i> Everything in sync.</span>
        </div>
        <p class="tan90-hero-footer">A little cooler. A lot more connected.</p>
    </section>

    <section class="tan90-login-panel">
        <x-auth-session-status class="mb-4" :status="session('status')" />

        <div class="tan90-panel-heading">
            <p>Secure access</p>
            <h2>Sign in to Tan90 ERP</h2>
        </div>

        <div class="tan90-auth-tabs" role="tablist" aria-label="Sign-in method">
            <button type="button" role="tab" aria-selected="{{ $loginMode === 'password' ? 'true' : 'false' }}" class="{{ $loginMode === 'password' ? 'is-active' : '' }}" wire:click="usePasswordLogin">Email and password</button>
            <button type="button" role="tab" aria-selected="{{ $loginMode === 'email-code' ? 'true' : 'false' }}" class="{{ $loginMode === 'email-code' ? 'is-active' : '' }}" wire:click="useEmailCodeLogin">Email code</button>
        </div>

        @if ($loginMode === 'password')
            <form wire:submit="login" class="tan90-manual-form">
                <div>
                    <x-input-label for="email" :value="__('Email')" />
                    <x-text-input wire:model="form.email" id="email" class="block mt-2 w-full" type="email" name="email" required autofocus autocomplete="username" />
                    <x-input-error :messages="$errors->get('form.email')" class="mt-2" />
                </div>

                <div>
                    <div class="tan90-password-label">
                        <x-input-label for="password" :value="__('Password')" />
                        <a href="{{ route('password.request', ['email' => $form->email]) }}">Forgot password?</a>
                    </div>
                    <x-text-input wire:model="form.password" id="password" class="block mt-2 w-full" type="password" name="password" required autocomplete="current-password" />
                    <x-input-error :messages="$errors->get('form.password')" class="mt-2" />
                </div>

                <div class="tan90-form-actions">
                    <label for="remember" class="inline-flex items-center">
                        <input wire:model="form.remember" id="remember" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" name="remember">
                        <span class="ms-2 text-sm text-slate-600">{{ __('Remember me') }}</span>
                    </label>
                    <x-primary-button>{{ __('Log in') }}</x-primary-button>
                </div>
            </form>
        @else
            <form wire:submit="loginWithEmailCode" class="tan90-manual-form">
                <div>
                    <x-input-label for="code-email" :value="__('Work email')" />
                    <x-text-input wire:model.live.debounce.350ms="form.email" id="code-email" class="block mt-2 w-full" type="email" name="code-email" required autocomplete="username" />
                    <x-input-error :messages="$errors->get('form.email')" class="mt-2" />
                    <p class="mt-2 text-sm text-slate-500">We’ll send a one-time sign-in code to your account email.</p>
                </div>

                @if ($codeRequested)
                    <div>
                        <x-input-label for="email-code" :value="__('6-digit email code')" />
                        <x-text-input wire:model="emailCode" id="email-code" class="block mt-2 w-full tracking-[0.35em]" type="text" name="email-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required />
                        <x-input-error :messages="$errors->get('emailCode')" class="mt-2" />
                    </div>
                    <div class="tan90-code-actions">
                        <button type="button" class="tan90-link-button" wire:click="sendEmailCode" wire:loading.attr="disabled">Resend code</button>
                        <x-primary-button>{{ __('Verify code and sign in') }}</x-primary-button>
                    </div>
                    <div class="tan90-remember-row">
                        <label for="code-remember" class="inline-flex items-center">
                            <input wire:model="form.remember" id="code-remember" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" name="code-remember">
                            <span class="ms-2 text-sm text-slate-600">{{ __('Remember me') }}</span>
                        </label>
                    </div>
                @else
                    <div class="tan90-code-actions">
                        <span></span>
                        <x-primary-button wire:click="sendEmailCode" type="button" wire:loading.attr="disabled">{{ __('Send sign-in code') }}</x-primary-button>
                    </div>
                @endif
            </form>
        @endif

        @production
            <p class="mt-5 text-sm text-slate-600">
                <a href="{{ route('grn.dashboard') }}" class="tan90-link-button">Open Store Manager workflow</a>
                <span class="block">Sign in with your Store Manager account.</span>
                <a href="/store-manager-demo.html" class="tan90-link-button">Open Store Manager demo</a>
            </p>
        @endproduction

        @if (config('app.demo_logins') && app()->environment(['local', 'staging', 'testing']))
        @php
            $coreDemoRoles = collect(\App\Enums\Role::cases())
                ->filter(fn ($role) => \App\Models\User::query()
                    ->where('role', $role->value)
                    ->whereIn('email', config('app.demo_login_emails', []))
                    ->where('is_active', true)
                    ->exists());
            // Super Admin is the same canonical tier as the core admin
            // shortcut above; do not render a duplicate top-tier entry.
            $grnCodes = array_merge(\App\Enums\Role::values(), ['ROLE-ADMIN', 'ROLE-SUPER']);
            $tan90Roles = \App\Models\Tan90\MasterData\Role::where('status', 'active')
                ->whereNotIn('code', $grnCodes)
                ->whereHas('userProfiles', fn ($profiles) => $profiles->whereHas('user', fn ($users) => $users
                    ->where('is_active', true)
                    ->whereIn('email', config('app.demo_login_emails', []))))
                ->orderBy('name')
                ->get();
        @endphp

        @if ($coreDemoRoles->isNotEmpty() || $tan90Roles->isNotEmpty())
        <div class="tan90-divider"><span>demo role shortcuts</span></div>

        {{-- Only expose a shortcut when an active account exists for its role. --}}
        <div class="tan90-role-grid">
            @foreach ($coreDemoRoles as $role)
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
            @foreach ($tan90Roles as $tan90Role)
                <a href="{{ route('tan90-role-login', $tan90Role->code) }}" class="tan90-role-card">
                    <span>{{ $tan90Role->name }}</span>
                    <small>{{ $tan90Role->data_scope ?: 'Operations workspace' }}</small>
                </a>
            @endforeach
        </div>
        @endif

        @php
            $demoUsers = \App\Models\User::with(['tan90Profile.role', 'accessRoles'])
                ->whereIn('email', config('app.demo_login_emails', []))
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
        @endphp
        <div class="tan90-panel-heading mt-6">
            <p>Staging role access</p>
            <h2>Choose a demo role</h2>
            <small>Each button opens a synthetic staging account with that role's permissions and site scope.</small>
        </div>
        <div class="tan90-role-grid">
            @foreach ($demoUsers as $demoUser)
                @php
                    $demoRole = $demoUser->accessRoles->pluck('name')->implode(', ')
                        ?: ($demoUser->tan90Profile?->role?->name
                            ?? $demoUser->role?->label()
                            ?? 'Demo User');
                @endphp
                <a href="{{ route('demo-user-login', $demoUser) }}" class="tan90-role-card">
                    <span>{{ $demoUser->name }}</span>
                    <small>{{ $demoRole }} - {{ $demoUser->email }}</small>
                </a>
            @endforeach
        </div>

        <div class="tan90-demo-access">
            <p>Explore Demo Accounts by Hierarchy</p>
            @php
                $demoGroups = [
                    'Level 1 / Super Admin' => ['superadmin@tan90.demo'],
                    'Level 2 / Heads of Vertical' => ['head.store@tan90.demo', 'head.quality@tan90.demo', 'head.procurement@tan90.demo', 'head.finance@tan90.demo', 'head.masterdata@tan90.demo', 'head.manufacturing@tan90.demo', 'head.fulfilment@tan90.demo'],
                    'Level 3 / Managers' => ['manager.grn@tan90.demo', 'manager.qc@tan90.demo', 'manager.vendor@tan90.demo', 'manager.finance@tan90.demo', 'manager.masterdata@tan90.demo', 'manager.production@tan90.demo', 'manager.quality.mfg@tan90.demo', 'manager.maintenance@tan90.demo', 'manager.warehouse@tan90.demo', 'manager.logistics@tan90.demo', 'manager.customer@tan90.demo'],
                    'Level 4 / Executives and Employees' => ['executive.grn@tan90.demo', 'executive.qc@tan90.demo', 'executive.grnqc@tan90.demo', 'executive.vendor@tan90.demo', 'executive.readonly@tan90.demo', 'executive.finance@tan90.demo', 'executive.masterdata@tan90.demo', 'planner@tan90.demo', 'supervisor@tan90.demo', 'operator@tan90.demo', 'qc.executive.mfg@tan90.demo', 'technician@tan90.demo', 'fgstore@tan90.demo', 'pickpack@tan90.demo', 'dispatch@tan90.demo', 'transport@tan90.demo', 'closure@tan90.demo'],
                ];
                $advancedDemoUsers = \App\Models\User::whereIn('email', collect($demoGroups)->flatten())
                    ->whereIn('email', config('app.demo_login_emails', []))
                    ->with(['accessRoles', 'accessPositions.vertical', 'accessPositions.unit', 'accessPositions.team', 'accessPositions.manager'])
                    ->get()
                    ->keyBy('email');
            @endphp
            @foreach ($demoGroups as $group => $emails)
                <details @open($loop->first)>
                    <summary>{{ $group }}</summary>
                    <div>
                        @foreach ($emails as $email)
                            @if ($advancedDemoUsers->has($email))
                                @php($advancedDemoUser = $advancedDemoUsers[$email])
                                <form method="post" action="{{ route('demo-login', $advancedDemoUser) }}">
                                    @csrf
                                    <button type="submit">
                                        <strong>{{ $advancedDemoUser->name }}</strong>
                                        <span>{{ $email }}</span>
                                        <small>{{ $advancedDemoUser->accessRoles->pluck('name')->implode(', ') ?: 'Advanced access' }}</small>
                                        @if($advancedDemoUser->accessPositions->first())
                                            @php($position = $advancedDemoUser->accessPositions->first())
                                            <small>{{ $position->vertical?->name ?? 'All verticals' }} / {{ $position->team?->name ?? 'All teams' }} / Reports to {{ $position->manager?->name ?? 'top level' }}</small>
                                        @endif
                                    </button>
                                </form>
                            @endif
                        @endforeach
                    </div>
                </details>
            @endforeach
        </div>
        @endif
    </section>
</div>
