<?php

use App\Support\SlaDirectives;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public bool $emailNotifications = true;
    public bool $smsAlerts = false;
    public string $theme = 'system';
    public string $slaDirective = '';
    public bool $justSaved = false;

    public function mount(): void
    {
        $prefs = auth()->user()->preferences ?? [];
        $this->emailNotifications = $prefs['email_notifications'] ?? true;
        $this->smsAlerts = $prefs['sms_alerts'] ?? false;
        $this->theme = $prefs['theme'] ?? 'system';
        $this->slaDirective = auth()->user()->sla_directive ?? '';
    }

    public function save(): void
    {
        auth()->user()->update([
            'preferences' => [
                'email_notifications' => $this->emailNotifications,
                'sms_alerts' => $this->smsAlerts,
                'theme' => $this->theme,
            ],
            'sla_directive' => $this->slaDirective ?: null,
        ]);

        $this->justSaved = true;
    }

    public function with(): array
    {
        return ['slaOptions' => SlaDirectives::OPTIONS];
    }
}; ?>

<div class="max-w-lg mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Settings</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Your personal preferences for this account.</p>

    <div class="rounded-lg border p-4 flex flex-col gap-4" style="background: var(--surface-3); border-color: var(--border);">
        <label class="flex items-center gap-2 text-sm">
            <input wire:model="emailNotifications" type="checkbox" class="w-4 h-4" />
            <span style="color: var(--text-primary);">Email notifications</span>
        </label>
        <label class="flex items-center gap-2 text-sm">
            <input wire:model="smsAlerts" type="checkbox" class="w-4 h-4" />
            <span style="color: var(--text-primary);">SMS alerts</span>
        </label>
        <label class="flex flex-col gap-1.5 text-sm">
            <span class="font-medium" style="color: var(--text-primary);">Theme</span>
            <select wire:model="theme" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                <option value="system">System</option>
                <option value="light">Light</option>
                <option value="dark">Dark</option>
            </select>
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
        <div class="flex items-center gap-3">
            <button wire:click="save" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Save changes</button>
            @if ($justSaved) <span class="text-xs" style="color: var(--status-good);">Saved</span> @endif
        </div>
    </div>
</div>
