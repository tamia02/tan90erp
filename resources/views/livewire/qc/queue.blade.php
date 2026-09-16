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

    /** @var array<int, array{name: string, value: string, result: string}> */
    public array $parameters = [];

    /** @var array<string, bool> */
    public array $documentsChecked = ['invoice' => false, 'eway' => false, 'lr' => false, 'pod' => false];

    public function openCheck(int $gateId): void
    {
        $this->checking = $gateId;
        $this->reset(['accepted', 'qcHold', 'defective', 'rejected', 'qcReasons', 'holdReason', 'holdDocument', 'parameters']);
        $this->documentsChecked = ['invoice' => false, 'eway' => false, 'lr' => false, 'pod' => false];
    }

    public function addParameter(): void
    {
        $this->parameters[] = ['name' => '', 'value' => '', 'result' => 'Pass'];
    }

    public function removeParameter(int $index): void
    {
        unset($this->parameters[$index]);
        $this->parameters = array_values($this->parameters);
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
            'parameters.*.name' => ['required_with:parameters.*.value', 'nullable', 'string', 'max:255'],
            'parameters.*.value' => ['required_with:parameters.*.name', 'nullable', 'string', 'max:255'],
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
            array_values(array_filter($this->parameters, fn ($p) => trim($p['name'] ?? '') !== '')),
            $this->documentsChecked,
        );

        $this->reset(['checking', 'accepted', 'qcHold', 'defective', 'rejected', 'qcReasons', 'holdReason', 'holdDocument', 'parameters', 'documentsChecked']);
    }

    public function with(): array
    {
        return ['queue' => GateEntry::where('status', 'grn')->orderBy('created_at')->get()];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">QC Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">QC Queue</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Split each delivery into accepted / hold / defective / rejected before it can go to GRN Check.</p>
    </section>

    <div class="flex flex-col gap-3">
        @forelse ($queue as $g)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="hover:opacity-80">
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->material }} · Invoice qty {{ $g->invoice_qty }}</div>
                    </a>
                    @if ($checking !== $g->id)
                        <button wire:click="openCheck({{ $g->id }})" class="rounded-lg px-3 py-1.5 text-sm font-medium text-white" style="background: var(--brand);">QC Check</button>
                    @endif
                </div>

                @if ($checking === $g->id)
                    @if ($errors->any())
                        <div class="mt-4 rounded-lg border p-3 text-xs" style="border-color: var(--status-critical); background: var(--status-critical-bg); color: var(--status-critical);">
                            Fix the highlighted field(s) below before submitting.
                        </div>
                    @endif
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4">
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Accepted</span>
                            <input wire:model="accepted" type="number" min="0" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                            @error('accepted') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">QC Hold</span>
                            <input wire:model.live="qcHold" type="number" min="0" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                            @error('qcHold') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Defective</span>
                            <input wire:model="defective" type="number" min="0" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                            @error('defective') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Rejected</span>
                            <input wire:model="rejected" type="number" min="0" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                            @error('rejected') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <div class="sm:col-span-4">
                            <span class="font-medium text-sm" style="color: var(--text-primary);">Documents checked</span>
                            <div class="flex flex-wrap gap-3 mt-2">
                                @foreach (['invoice' => 'Invoice', 'eway' => 'E-way Bill', 'lr' => 'LR/LRC', 'pod' => 'POD'] as $key => $label)
                                    <label class="flex items-center gap-1.5 text-sm">
                                        <input type="checkbox" wire:model="documentsChecked.{{ $key }}" class="rounded" />
                                        <span style="color: var(--text-primary);">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <div class="sm:col-span-4">
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-medium text-sm" style="color: var(--text-primary);">Product parameters (optional)</span>
                                <button type="button" wire:click="addParameter" class="text-xs font-medium" style="color: var(--brand);">+ Add parameter</button>
                            </div>
                            <div class="flex flex-col gap-2">
                                @foreach ($parameters as $i => $param)
                                    <div class="grid grid-cols-1 sm:grid-cols-[2fr_2fr_1fr_auto] gap-2 items-start">
                                        <input wire:model="parameters.{{ $i }}.name" placeholder="Parameter (e.g. Moisture %)" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                                        <input wire:model="parameters.{{ $i }}.value" placeholder="Observed value" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                                        <select wire:model="parameters.{{ $i }}.result" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border); background: var(--surface-1); color: var(--text-primary);">
                                            <option value="Pass">Pass</option>
                                            <option value="Fail">Fail</option>
                                        </select>
                                        <button type="button" wire:click="removeParameter({{ $i }})" class="text-xs font-medium px-2 py-2" style="color: var(--status-critical);">Remove</button>
                                    </div>
                                    @error("parameters.{$i}.name") <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                                @endforeach
                                @if (empty($parameters))
                                    <p class="text-xs" style="color: var(--text-muted);">No parameters added — add one for any physical/chemical spec checked (moisture, temperature, dimensions, etc.).</p>
                                @endif
                            </div>
                        </div>
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
