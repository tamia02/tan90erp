<?php

use App\Models\GateEntry;
use App\Services\QcService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public ?int $checking = null;
    public string $accepted = '';
    public string $qcHold = '';
    public string $defective = '';
    public string $rejected = '';
    public string $qcReasons = '';
    public string $holdReason = '';
    public $holdDocument = null;

    public function openCheck(int $gateId): void
    {
        $this->checking = $gateId;
        $this->reset(['accepted', 'qcHold', 'defective', 'rejected', 'qcReasons', 'holdReason', 'holdDocument']);
    }

    public function submitCheck(): void
    {
        $this->validate([
            'accepted' => ['required', 'integer', 'min:0'],
            'qcHold' => ['required', 'integer', 'min:0'],
            'defective' => ['required', 'integer', 'min:0'],
            'rejected' => ['required', 'integer', 'min:0'],
            'holdReason' => [$this->qcHold > 0 ? 'required' : 'nullable', 'string', 'max:1000'],
            'holdDocument' => ['nullable', 'file', 'max:10240'],
        ]);

        $gate = GateEntry::findOrFail($this->checking);

        $holdDocumentPath = $this->holdDocument?->store('qc-hold-documents', 'local');

        app(QcService::class)->recordResult(
            $gate,
            $gate->material,
            $gate->invoice_qty ?? 0,
            $gate->invoice_qty ?? 0,
            [
                'accepted' => (int) $this->accepted,
                'qcHold' => (int) $this->qcHold,
                'defective' => (int) $this->defective,
                'rejected' => (int) $this->rejected,
            ],
            $this->qcReasons ?: null,
            $this->holdReason ?: null,
            $holdDocumentPath,
        );

        $this->reset(['checking', 'accepted', 'qcHold', 'defective', 'rejected', 'qcReasons', 'holdReason', 'holdDocument']);
    }

    public function with(): array
    {
        return ['queue' => GateEntry::where('status', 'grn')->orderBy('created_at')->get()];
    }
}; ?>

<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">QC Queue</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Split each delivery into accepted / hold / defective / rejected before it can go to GRN Check.</p>

    <div class="flex flex-col gap-3">
        @forelse ($queue as $g)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->material }} · Invoice qty {{ $g->invoice_qty }}</div>
                    </div>
                    @if ($checking !== $g->id)
                        <button wire:click="openCheck({{ $g->id }})" class="rounded-lg px-3 py-1.5 text-sm font-medium text-white" style="background: var(--brand);">QC Check</button>
                    @endif
                </div>

                @if ($checking === $g->id)
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4">
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Accepted</span>
                            <input wire:model="accepted" type="number" min="0" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">QC Hold</span>
                            <input wire:model.live="qcHold" type="number" min="0" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Defective</span>
                            <input wire:model="defective" type="number" min="0" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Rejected</span>
                            <input wire:model="rejected" type="number" min="0" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm sm:col-span-4">
                            <span class="font-medium" style="color: var(--text-primary);">QC reasons (if any hold/defective/rejected)</span>
                            <textarea wire:model="qcReasons" rows="2" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);"></textarea>
                        </label>
                        @if ((int) $qcHold > 0)
                            <label class="flex flex-col gap-1.5 text-sm sm:col-span-2">
                                <span class="font-medium" style="color: var(--text-primary);">Quality Hold reason</span>
                                <textarea wire:model="holdReason" rows="2" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Why is this quantity on hold?"></textarea>
                                @error('holdReason') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            </label>
                            <label class="flex flex-col gap-1.5 text-sm sm:col-span-2">
                                <span class="font-medium" style="color: var(--text-primary);">Attach document (optional)</span>
                                <input wire:model="holdDocument" type="file" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                                @error('holdDocument') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            </label>
                        @endif
                        <button wire:click="submitCheck" class="sm:col-span-4 rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Submit QC Check &amp; send to GRN Check</button>
                    </div>
                @endif
            </div>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">Nothing waiting for QC.</div>
        @endforelse
    </div>
</div>
