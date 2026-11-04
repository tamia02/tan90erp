<?php

use App\Models\User;
use App\Services\Auth\PasswordResetCodeService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $email = '';

    public string $code = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $codeRequested = false;

    public function mount(): void
    {
        $this->email = request()->string('email')->toString();
    }

    /** Send a password reset code without revealing whether the email is registered. */
    public function sendPasswordResetCode(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        app(PasswordResetCodeService::class)->send($this->email);
        $this->codeRequested = true;
        $this->code = '';
        $this->resetErrorBag('code');
        $this->resetErrorBag('email');
        session()->flash('status', 'If an active Tan90 account uses this email, a reset code has been sent.');
    }

    public function resetPassword(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        app(PasswordResetCodeService::class)->reset($this->email, $this->code, $this->password);
        $user = User::query()->where('email', mb_strtolower(trim($this->email)))->first();
        if ($user) {
            Event::dispatch(new PasswordReset($user));
        }

        session()->flash('status', 'Your password has been reset. You can now sign in.');
        $this->redirectRoute('login', navigate: true);
    }
}; ?>

<div>
    <div class="mb-4 text-sm text-gray-600">
        {{ __('Forgot your password? Enter your account email and we will send a one-time code to verify your request.') }}
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form wire:submit="sendPasswordResetCode">
        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input wire:model="email" id="email" class="block mt-1 w-full" type="email" name="email" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>
        @unless ($codeRequested)
            <div class="flex items-center justify-end mt-4">
                <x-primary-button>{{ __('Send reset code') }}</x-primary-button>
            </div>
        @endunless
    </form>

    @if ($codeRequested)
        <form wire:submit="resetPassword" class="mt-5 space-y-4">
            <div>
                <x-input-label for="reset-code" :value="__('6-digit email code')" />
                <x-text-input wire:model="code" id="reset-code" class="block mt-1 w-full tracking-[0.35em]" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required />
                <x-input-error :messages="$errors->get('code')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="reset-password" :value="__('New password')" />
                <x-text-input wire:model="password" id="reset-password" class="block mt-1 w-full" type="password" autocomplete="new-password" required />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="reset-password-confirmation" :value="__('Confirm new password')" />
                <x-text-input wire:model="password_confirmation" id="reset-password-confirmation" class="block mt-1 w-full" type="password" autocomplete="new-password" required />
            </div>
            <div class="flex items-center justify-between gap-3">
                <button type="button" wire:click="sendPasswordResetCode" wire:loading.attr="disabled" class="text-sm font-semibold text-indigo-600 hover:text-indigo-700">Resend code</button>
                <x-primary-button>{{ __('Reset password') }}</x-primary-button>
            </div>
        </form>
    @endif

    <p class="mt-5 text-sm text-slate-600">
        Remembered your password?
        <a class="font-semibold text-indigo-600 hover:text-indigo-700" href="{{ route('login') }}">Return to sign in</a>
    </p>
</div>
