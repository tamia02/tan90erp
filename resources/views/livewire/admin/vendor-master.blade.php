<?php

use App\Models\VendorMaster;
use App\Models\VendorStockUpdate;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public bool $adding = false;
    public ?int $expanded = null;

    public string $vendorName = '';
    public string $gstNumber = '';
    public string $contactPhone = '';
    public string $contactEmail = '';
    public string $category = 'Raw Material — PCM Compound';
    public bool $active = true;
    public string $website = '';
    public string $glAccount = 'Raw Materials — COGS';
    public string $addressCity = '';
    public string $addressState = '';
    public string $description = '';

    public array $glAccounts = ['Raw Materials — COGS', 'Packaging — COGS', 'Consumables — Expense', 'Freight & Logistics', 'Other Purchases'];

    public function addVendor(): void
    {
        $this->validate([
            'vendorName' => ['required', 'string', 'max:255'],
            'gstNumber' => ['required', 'string', 'max:20'],
        ]);

        $entry = VendorMaster::create([
            'vendor_name' => $this->vendorName,
            'gst_number' => $this->gstNumber,
            'contact_phone' => $this->contactPhone,
            'contact_email' => $this->contactEmail ?: null,
            'category' => $this->category,
            'active' => $this->active,
            'website' => $this->website ?: null,
            'gl_account' => $this->glAccount,
            'address_city' => $this->addressCity ?: null,
            'address_state' => $this->addressState ?: null,
            'address_country' => $this->addressCity ? 'India' : null,
            'description' => $this->description ?: null,
        ]);

        AuditLogger::log('Vendor added to master', "{$entry->vendor_name} · {$entry->gst_number}", $entry);

        $this->reset(['vendorName', 'gstNumber', 'contactPhone', 'contactEmail', 'website', 'addressCity', 'addressState', 'description', 'adding']);
        $this->category = 'Raw Material — PCM Compound';
        $this->glAccount = 'Raw Materials — COGS';
        $this->active = true;
    }

    public function deleteVendor(int $id): void
    {
        $entry = VendorMaster::findOrFail($id);
        AuditLogger::log('Vendor removed from master', $entry->vendor_name);
        $entry->delete();
    }

    public function with(): array
    {
        return [
            'vendors' => VendorMaster::orderByDesc('created_at')->get(),
            'stockUpdates' => VendorStockUpdate::orderByDesc('created_at')->get(),
        ];
    }
}; ?>

<div class="max-w-5xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
        <div>
            <h1 class="text-xl sm:text-2xl font-semibold" style="color: var(--text-primary);">Vendor Master</h1>
            <p class="text-sm mt-1" style="color: var(--text-secondary);">Every registered vendor, matching the client's Zoho CRM Vendors module, plus the GST our own gate check needs.</p>
        </div>
        <button wire:click="$toggle('adding')" class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">
            {{ $adding ? 'Cancel' : 'Add vendor' }}
        </button>
    </div>


    @if ($adding)
        <div class="rounded-lg border p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3" style="background: var(--surface-3); border-color: var(--border);">
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Vendor name</span>
                <input wire:model="vendorName" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                @error('vendorName') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">GST number</span>
                <input wire:model="gstNumber" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="27AACCH1234K1Z5" />
                @error('gstNumber') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Contact phone</span>
                <input wire:model="contactPhone" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Contact email</span>
                <input wire:model="contactEmail" type="email" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Website</span>
                <input wire:model="website" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Category</span>
                <input wire:model="category" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">GL Account</span>
                <select wire:model="glAccount" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    @foreach ($glAccounts as $g) <option value="{{ $g }}">{{ $g }}</option> @endforeach
                </select>
            </label>
            <div class="grid grid-cols-2 gap-3">
                <label class="flex flex-col gap-1.5 text-sm">
                    <span class="font-medium" style="color: var(--text-primary);">City</span>
                    <input wire:model="addressCity" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                </label>
                <label class="flex flex-col gap-1.5 text-sm">
                    <span class="font-medium" style="color: var(--text-primary);">State</span>
                    <input wire:model="addressState" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                </label>
            </div>
            <label class="flex flex-col gap-1.5 text-sm sm:col-span-2">
                <span class="font-medium" style="color: var(--text-primary);">Description</span>
                <textarea wire:model="description" rows="2" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);"></textarea>
            </label>
            <label class="flex items-center gap-2 text-sm sm:col-span-2">
                <input wire:model="active" type="checkbox" class="w-4 h-4" />
                <span style="color: var(--text-primary);">Active vendor</span>
            </label>
            <button wire:click="addVendor" class="sm:col-span-2 rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Add to master</button>
        </div>
    @endif

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="px-2 py-2.5"></th>
                        <th class="px-4 py-2.5 font-medium">Vendor Name</th>
                        <th class="px-4 py-2.5 font-medium">Email</th>
                        <th class="px-4 py-2.5 font-medium">Phone</th>
                        <th class="px-4 py-2.5 font-medium">GST</th>
                        <th class="px-4 py-2.5 font-medium">Active</th>
                        <th class="px-4 py-2.5 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($vendors as $v)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-2 py-2.5">
                                <button wire:click="$set('expanded', {{ $expanded === $v->id ? 'null' : $v->id }})" style="color: var(--text-muted);">{{ $expanded === $v->id ? '▾' : '▸' }}</button>
                            </td>
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">{{ $v->vendor_name }}</td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);">{{ $v->contact_email ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);">{{ $v->contact_phone }}</td>
                            <td class="px-4 py-2.5 font-mono text-xs" style="color: var(--text-secondary);">{{ $v->gst_number }}</td>
                            <td class="px-4 py-2.5">{{ $v->active ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <button wire:click="deleteVendor({{ $v->id }})" wire:confirm="Remove {{ $v->vendor_name }}?" style="color: var(--status-critical);">Remove</button>
                            </td>
                        </tr>
                        @if ($expanded === $v->id)
                            <tr style="background: var(--surface-2);">
                                <td></td>
                                <td colspan="6" class="px-4 py-3">
                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Category</div><div style="color: var(--text-primary);">{{ $v->category }}</div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">GL Account</div><div style="color: var(--text-primary);">{{ $v->gl_account ?? '—' }}</div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Website</div><div style="color: var(--text-primary);">{{ $v->website ?? '—' }}</div></div>
                                        <div><div class="font-semibold mb-0.5" style="color: var(--text-muted);">Location</div><div style="color: var(--text-primary);">{{ $v->address_city ? "{$v->address_city}, {$v->address_state}" : '—' }}</div></div>
                                    </div>
                                    @if ($v->description)
                                        <div class="text-xs mt-2"><span class="font-semibold" style="color: var(--text-muted);">Description: </span><span style="color: var(--text-primary);">{{ $v->description }}</span></div>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No vendors registered yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <h2 class="font-semibold text-sm mt-8 mb-3" style="color: var(--text-primary);">Vendor stock updates</h2>
    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="px-4 py-2.5 font-medium">Vendor</th>
                        <th class="px-4 py-2.5 font-medium">Material</th>
                        <th class="px-4 py-2.5 font-medium">Quantity</th>
                        <th class="px-4 py-2.5 font-medium">Note</th>
                        <th class="px-4 py-2.5 font-medium">Updated</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stockUpdates as $u)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">{{ $u->vendor_name }}</td>
                            <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $u->material }}</td>
                            <td class="px-4 py-2.5" style="color: var(--text-primary);">{{ $u->quantity }} {{ $u->unit }}</td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-muted);">{{ $u->note ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-muted);">{{ $u->created_at->format('d M Y, H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No vendor stock updates yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
