<?php

use App\Models\CustomFieldDefinition;
use App\Services\AuditLogger;
use App\Support\CustomFields;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

// Master Data > Custom Fields: the team adds fields to any table themselves -
// pick the table, name the field, choose the data type. The field then shows
// on that table's add / edit form and detail page.
new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public string $table = CustomFields::INVENTORY_ITEMS;
    public $csv;
    public array $csvErrors = [];
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
        $this->reset(['csv', 'csvErrors']);
    }

    // Bulk update: every record of the table with its current values; edit in
    // Excel and upload it back.
    public function downloadCsv()
    {
        abort_unless(CustomFields::canManage(auth()->user()), 403);
        $fields = CustomFields::fields($this->table);
        ['columns' => $columns, 'rows' => $rows] = CustomFields::recordsForCsv($this->table);
        $name = str(CustomFields::tables()[$this->table])->after(': ')->slug().'-custom-fields-'.now()->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($fields, $columns, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel reads UTF-8
            fputcsv($out, array_merge(['ID'], array_values($columns), $fields->pluck('label')->all()));
            foreach ($rows as $row) {
                fputcsv($out, array_merge([$row['id']], array_map(fn ($c) => $row[$c] ?? '', array_keys($columns)),
                    $fields->map(fn ($f) => $this->csvValue($f, $row['values'][$f->field_key] ?? null))->all()));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function csvValue($field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($field->input_type) {
            'yes_no' => $value ? 'Yes' : 'No',
            'number' => rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.'),
            default => (string) $value,
        };
    }

    // Checks every row first; saves only if the whole file is correct.
    // A blank cell leaves that value as it is.
    public function uploadCsv(): void
    {
        abort_unless(CustomFields::canManage(auth()->user()), 403);
        $this->csvErrors = [];
        $this->validate(['csv' => ['required', 'file', 'mimes:csv,txt', 'max:10240']], ['csv.required' => 'Choose the CSV file first.']);

        $fields = CustomFields::fields($this->table);
        $handle = fopen($this->csv->getRealPath(), 'r');
        $header = fgetcsv($handle) ?: [];
        $header = array_map(fn ($h) => mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
        $idColumn = array_search('id', $header, true);
        if ($idColumn === false) {
            $this->csvErrors[] = 'The first row must have an "ID" column - start from the downloaded CSV.';

            return;
        }
        $fieldColumns = [];
        foreach ($fields as $field) {
            foreach ([mb_strtolower($field->label), $field->field_key] as $name) {
                if (($col = array_search($name, $header, true)) !== false) {
                    $fieldColumns[$field->field_key] = $col;
                    break;
                }
            }
        }
        if (! $fieldColumns) {
            $this->csvErrors[] = 'No custom field columns found - the column names must match the field names on this table.';

            return;
        }

        $known = CustomFields::recordsForCsv($this->table)['rows']->pluck('id')->flip();
        $rules = collect(CustomFields::rules($fields, 'v'))->map(fn ($r) => array_map(fn ($x) => $x === 'required' ? 'nullable' : $x, $r))->all();
        $names = CustomFields::attributeNames($fields, 'v');
        $updates = [];
        $line = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($row, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $id = (int) trim((string) ($row[$idColumn] ?? ''));
            if (! $known->has($id)) {
                $this->csvErrors[] = "Row {$line}: ID \"".($row[$idColumn] ?? '')."\" is not a record of this table.";

                continue;
            }
            $values = [];
            foreach ($fieldColumns as $key => $col) {
                $cell = trim((string) ($row[$col] ?? ''));
                if ($cell === '') {
                    continue;
                }
                $field = $fields->firstWhere('field_key', $key);
                if ($field->input_type === 'yes_no') {
                    $cell = in_array(mb_strtolower($cell), ['yes', 'y', '1', 'true'], true) ? '1' : (in_array(mb_strtolower($cell), ['no', 'n', '0', 'false'], true) ? '0' : $cell);
                }
                if ($field->input_type === 'date' && ($parsed = $this->parseDate($cell))) {
                    $cell = $parsed;
                }
                $values[$key] = $cell;
            }
            $validator = validator(['v' => $values], array_intersect_key($rules, array_flip(array_map(fn ($k) => "v.{$k}", array_keys($values)))), [], $names);
            foreach ($validator->errors()->all() as $message) {
                $this->csvErrors[] = "Row {$line} (ID {$id}): {$message}";
            }
            if ($values) {
                $updates[$id] = CustomFields::clean($fields, $values);
            }
        }
        fclose($handle);

        if ($this->csvErrors) {
            array_unshift($this->csvErrors, 'Nothing was saved. Fix these rows and upload again:');
            $this->csvErrors = array_slice($this->csvErrors, 0, 51);

            return;
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($updates) {
            foreach ($updates as $id => $values) {
                CustomFields::saveFor($this->table, $id, $values);
            }
        });
        AuditLogger::log('Custom fields bulk updated by CSV', CustomFields::tables()[$this->table].' · '.count($updates).' records');
        session()->flash('success', count($updates).' records of '.CustomFields::tables()[$this->table].' updated from the CSV.');
        $this->reset(['csv']);
    }

    // Excel often saves dates as 31/03/2027 or 31-03-2027.
    private function parseDate(string $cell): ?string
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd M Y', 'j/n/Y'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $cell);
            if ($date && $date->format($format) === $cell) {
                return $date->format('Y-m-d');
            }
        }

        return null;
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

    @if ($fields->where('active', true)->isNotEmpty())
        <div class="rounded-2xl border p-5 mt-5" style="background: var(--surface-3); border-color: var(--border);">
            <h2 class="text-base font-semibold" style="color: var(--text-primary);">Bulk update by CSV</h2>
            <p class="text-xs mt-1 mb-3" style="color: var(--text-muted);">1. Download the CSV - every record of {{ $tables[$table] }} with its current values. 2. Fill the field columns in Excel (keep the ID column; dates as 31/03/2027 or 2027-03-31; Yes/No as Yes or No). 3. Upload it. Blank cells are left as they are. Every row is checked first - if anything is wrong, nothing is saved and you see the row to fix.</p>
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" wire:click="downloadCsv" class="rounded-lg border px-4 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--brand);">Download CSV</button>
                <form wire:submit="uploadCsv" class="flex flex-wrap items-center gap-2">
                    <input type="file" wire:model="csv" accept=".csv,text/csv" class="text-xs">
                    <button type="submit" wire:loading.attr="disabled" wire:target="csv,uploadCsv" class="rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-50" style="background: var(--brand);">Upload CSV</button>
                </form>
            </div>
            @error('csv') <div class="text-xs mt-2" style="color: var(--status-critical);">{{ $message }}</div> @enderror
            @if ($csvErrors)
                <div role="alert" class="rounded-lg border p-3 mt-3 text-xs space-y-0.5" style="border-color: var(--status-critical); background: var(--status-critical-bg); color: var(--status-critical);">
                    @foreach ($csvErrors as $error)<div>{{ $error }}</div>@endforeach
                </div>
            @endif
        </div>
    @endif
</div>
