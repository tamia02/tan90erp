<?php

use App\Enums\Role;
use App\Livewire\Forms\LoginForm;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        $home = $this->roleHomeUrl(Auth::user()->role);
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
        </div>

        <div class="tan90-divider"><span>or sign in manually</span></div>

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
