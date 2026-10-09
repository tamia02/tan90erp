<?php

use App\Models\CustomFieldDefinition;
use App\Services\AuditLogger;
use App\Support\CustomFields;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

// Master Data > Custom Fields: the team adds fields to any table themselves -
// pick the table, name the field, choose the data type. The field then shows
// on that table's add / edit form and detail page.
new #[Layout('layouts.app')] class extends Component
{
    public string $table = CustomFields::INVENTORY_ITEMS;
    public string $label = '';
    public string $type = 'text';
    public string $options = '';
    public bool $required = false;

    public function mount(): void
    {
        abort_unless(CustomFields::canManage(auth()->user()), 403, 'Ask the Super Admin for "Add custom fields to any table".');
        if (request()->filled('table') && isset(CustomFields::tables()[request('table')])) {
            $this->table = request('table');
        }
    }

    public function updatedTable(): void
    {
        $this->resetValidation();
    }

    public function add(): void
    {
        abort_unless(CustomFields::canManage(auth()->user()), 403);
        $this->validate([
            'table' => ['required', Rule::in(array_keys(CustomFields::tables()))],
            'label' => ['required', 'string', 'max:60'],
            'type' => ['required', Rule::in(array_keys(CustomFields::TYPES))],
            'options' => [Rule::requiredIf($this->type === 'dropdown'), 'nullable', 'string', 'max:1000'],
        ], ['options.required' => 'List the dropdown choices, separated by commas.'], ['label' => 'field name', 'type' => 'data type']);

        $field = CustomFields::add($this->table, $this->label, $this->type, $this->options, $this->required, auth()->user());
        AuditLogger::log('Custom field added', CustomFields::tables()[$this->table]." · {$field->label} (".CustomFields::TYPES[$field->input_type].')', $field);
        session()->flash('success', "\"{$field->label}\" added to ".CustomFields::tables()[$this->table].' - it now shows on its form.');
        $this->reset(['label', 'type', 'options', 'required']);
    }

    public function toggle(int $id): void
    {
        abort_unless(CustomFields::canManage(auth()->user()), 403);
        $field = CustomFieldDefinition::findOrFail($id);
        $field->update(['active' => ! $field->active]);
        AuditLogger::log($field->active ? 'Custom field turned on' : 'Custom field turned off', "{$field->entity} · {$field->label}", $field);
    }

    public function move(int $id, int $direction): void
    {
        abort_unless(CustomFields::canManage(auth()->user()), 403);
        $fields = CustomFields::fields($this->table, false)->values();
        $index = $fields->search(fn ($f) => $f->id === $id);
        $swap = $fields->get($index + $direction);
        if ($index === false || ! $swap) {
            return;
        }
        $order = $fields->pluck('id')->all();
        [$order[$index], $order[$index + $direction]] = [$order[$index + $direction], $order[$index]];
        foreach ($order as $position => $fieldId) {
            CustomFieldDefinition::whereKey($fieldId)->update(['sort_order' => $position + 1]);
        }
    }

    public function with(): array
    {
        return [
            'tables' => CustomFields::tables(),
            'types' => CustomFields::TYPES,
            'fields' => CustomFields::fields($this->table, false),
            'counts' => CustomFieldDefinition::where('active', true)->groupBy('entity')->selectRaw('entity, COUNT(*) c')->pluck('c', 'entity'),
            'formLink' => $this->table === CustomFields::INVENTORY_ITEMS
                ? route('inventory.items')
                : (str_starts_with($this->table, 'master:') ? route('tan90.master-data.create', substr($this->table, 7)) : null),
        ];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <h1 class="text-2xl font-bold" style="color: var(--text-primary);">Custom Fields</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Add your own fields to any table: choose the table, name the field and pick its data type. The field shows straight away on that table's add / edit form and detail page. Turn a field off to hide it - values already filled are kept.</p>
    </section>
    @if (session('success'))
        <div role="status" class="rounded-xl border px-4 py-3 mb-4 text-sm font-medium" style="background: var(--status-good-bg); border-color: var(--status-good); color: var(--status-good);">{{ session('success') }}</div>
    @endif

    <div class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <label class="flex flex-col gap-1 text-sm max-w-xl"><span class="font-medium">Table</span>
            <select wire:model.live="table" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                @foreach ($tables as $key => $name)<option value="{{ $key }}">{{ $name }}{{ ($counts[$key] ?? 0) ? ' ('.$counts[$key].' '.str('field')->plural($counts[$key]).')' : '' }}</option>@endforeach
            </select>
        </label>

        <form wire:submit="add" class="grid sm:grid-cols-2 lg:grid-cols-6 gap-3 items-start mt-4">
            <label class="flex flex-col gap-1 text-xs lg:col-span-2"><span class="font-medium">Field name</span>
                <input wire:model="label" placeholder="e.g. Cost Price, Shelf Life, Hazard Class" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                @error('label') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror</label>
            <label class="flex flex-col gap-1 text-xs"><span class="font-medium">Data type</span>
                <select wire:model.live="type" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    @foreach ($types as $value => $name)<option value="{{ $value }}">{{ $name }}</option>@endforeach
                </select></label>
            @if ($type === 'dropdown')
                <label class="flex flex-col gap-1 text-xs lg:col-span-2"><span class="font-medium">Choices (comma separated)</span>
                    <input wire:model="options" placeholder="e.g. Class A, Class B, Class C" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                    @error('options') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror</label>
            @endif
            <label class="flex items-center gap-2 text-xs mt-6"><input type="checkbox" wire:model="required"> Must be filled</label>
            <div class="lg:col-span-6"><button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium text-white" style="background: var(--brand);">Add field</button></div>
        </form>
    </div>

    <div class="rounded-2xl border overflow-x-auto" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex items-center justify-between px-4 pt-4">
            <h2 class="text-base font-semibold" style="color: var(--text-primary);">Fields on {{ $tables[$table] }}</h2>
            @if ($formLink)<a href="{{ $formLink }}" class="text-xs font-semibold" style="color: var(--brand);">Open its form →</a>@endif
        </div>
        <table class="w-full text-sm min-w-[640px] mt-2">
            <thead><tr class="text-left text-xs uppercase" style="color: var(--text-muted);"><th class="px-4 py-2">Order</th><th class="px-4 py-2">Field</th><th class="px-4 py-2">Data type</th><th class="px-4 py-2">Choices</th><th class="px-4 py-2">Must be filled</th><th class="px-4 py-2"></th></tr></thead>
            <tbody>
                @forelse ($fields as $field)
                    <tr wire:key="cfd-{{ $field->id }}" style="border-top: 1px solid var(--border);{{ $field->active ? '' : ' opacity: .55;' }}">
                        <td class="px-4 py-2 whitespace-nowrap text-xs">
                            <button type="button" wire:click="move({{ $field->id }}, -1)" title="Move up" style="color: var(--brand);">▲</button>
                            <button type="button" wire:click="move({{ $field->id }}, 1)" title="Move down" style="color: var(--brand);">▼</button>
                        </td>
                        <td class="px-4 py-2 font-medium">{{ $field->label }}</td>
                        <td class="px-4 py-2">{{ $types[$field->input_type] ?? $field->input_type }}</td>
                        <td class="px-4 py-2 text-xs">{{ implode(', ', $field->options ?? []) }}</td>
                        <td class="px-4 py-2">{{ $field->required ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2 text-right"><button type="button" wire:click="toggle({{ $field->id }})" class="text-xs font-semibold" style="color: {{ $field->active ? 'var(--status-critical)' : 'var(--brand)' }};">{{ $field->active ? 'Turn off' : 'Turn on' }}</button></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center" style="color: var(--text-muted);">No custom fields on this table yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
