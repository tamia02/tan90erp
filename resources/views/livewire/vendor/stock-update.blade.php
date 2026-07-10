<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use App\Models\VendorStockUpdate;
use App\Services\AuditLogger;

new #[Layout('layouts.app')] class extends Component
{
    private const UNITS = ['KG', 'LTR', 'MTR', 'NOS', 'BOX', 'TON'];

    public ?int $editingId = null;

    #[Validate('required|string|max:255')]
    public string $material = '';

    #[Validate('required|integer|min:0')]
    public ?int $quantity = null;

    #[Validate('required|in:KG,LTR,MTR,NOS,BOX,TON')]
    public string $unit = 'KG';

    public function submit(): void
    {
        $this->validate();

        $update = VendorStockUpdate::create([
            'vendor_name' => auth()->user()->name,
            'material' => $this->material,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
        ]);

        AuditLogger::log('Vendor stock updated', "{$update->material} · {$update->quantity} {$update->unit}");

        $this->material = '';
        $this->quantity = null;
        $this->unit = 'KG';

        session()->flash('success', 'Stock updated successfully.');
    }

    public function edit(int $id): void
    {
        $update = VendorStockUpdate::where('vendor_name', auth()->user()->name)->findOrFail($id);

        $this->editingId = $update->id;
        $this->material = $update->material;
        $this->quantity = $update->quantity;
        $this->unit = $update->unit;
    }

    public function saveEdit(): void
    {
        $this->validate();

        $update = VendorStockUpdate::where('vendor_name', auth()->user()->name)->findOrFail($this->editingId);
        $update->update([
            'material' => $this->material,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
        ]);

        AuditLogger::log('Vendor stock updated', "{$update->material} · {$update->quantity} {$update->unit} (edited)");

        $this->cancelEdit();
        session()->flash('success', 'Stock entry updated successfully.');
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'material', 'quantity', 'unit']);
        $this->unit = 'KG';
    }

    public function with(): array
    {
        return [
            'units' => self::UNITS,
            'updates' => VendorStockUpdate::where('vendor_name', auth()->user()->name)
                                ->orderBy('created_at', 'desc')
                                ->take(10)
                                ->get(),
        ];
    }
}; ?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Stock Update</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Update daily available stock for raw materials.</p>

    <div class="grid grid-cols-3 gap-6">
        <div class="col-span-1">
            <form wire:submit="{{ $editingId ? 'saveEdit' : 'submit' }}" class="p-4 rounded-lg border space-y-4" style="background: var(--surface-3); border-color: var(--border);">
                @if (session()->has('success'))
                    <div class="p-2 rounded text-xs text-green-800 bg-green-100">{{ session('success') }}</div>
                @endif
                @if ($editingId)
                    <div class="p-2 rounded text-xs" style="background: var(--brand-bg); color: var(--brand);">Editing existing stock entry</div>
                @endif
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);">SKU/Material</label>
                    <input type="text" wire:model="material" class="w-full rounded border px-3 py-2 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);">
                    @error('material') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);">Available Qty</label>
                    <input type="number" wire:model="quantity" class="w-full rounded border px-3 py-2 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);">
                    @error('quantity') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);">Unit</label>
                    <select wire:model="unit" class="w-full rounded border px-3 py-2 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);">
                        @foreach ($units as $u)
                            <option value="{{ $u }}">{{ $u }}</option>
                        @endforeach
                    </select>
                    @error('unit') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 px-4 py-2 rounded text-sm font-medium text-white" style="background: var(--primary);">{{ $editingId ? 'Save Changes' : 'Update Stock' }}</button>
                    @if ($editingId)
                        <button type="button" wire:click="cancelEdit" class="px-4 py-2 rounded text-sm font-medium border" style="border-color: var(--border); color: var(--text-primary);">Cancel</button>
                    @endif
                </div>
            </form>
        </div>

        <div class="col-span-2">
            <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                            <th class="px-4 py-2.5 font-medium">SKU</th>
                            <th class="px-4 py-2.5 font-medium">Quantity</th>
                            <th class="px-4 py-2.5 font-medium">Updated On</th>
                            <th class="px-4 py-2.5 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($updates as $upd)
                            <tr style="border-top: 1px solid var(--border);">
                                <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">{{ $upd->material }}</td>
                                <td class="px-4 py-2.5" style="color: var(--text-primary);">{{ $upd->quantity }} {{ $upd->unit }}</td>
                                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $upd->created_at->format('d M, Y H:i') }}</td>
                                <td class="px-4 py-2.5 text-right">
                                    <button wire:click="edit({{ $upd->id }})" class="text-xs font-medium" style="color: var(--brand);">Update</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No stock updates found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
