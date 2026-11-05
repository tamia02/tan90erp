{{-- One custom field's input, by data type. Livewire forms pass $wire (e.g. "customValues.cost_price"); plain forms pass $name (e.g. "custom[cost_price]") and $value. --}}
@php
    $bind = isset($wire) ? 'wire:model="'.$wire.'"' : 'name="'.$name.'"';
    $val = $value ?? null;
    $cls = 'rounded-lg border px-3 py-2 text-sm w-full';
@endphp
@switch ($field->input_type)
    @case('dropdown')
        <select {!! $bind !!} class="{{ $cls }}" style="border-color: var(--border);">
            <option value="">-</option>
            @foreach ($field->options ?? [] as $option)<option value="{{ $option }}" @selected(! isset($wire) && (string) $val === (string) $option)>{{ $option }}</option>@endforeach
        </select>
        @break
    @case('yes_no')
        <select {!! $bind !!} class="{{ $cls }}" style="border-color: var(--border);">
            <option value="">-</option>
            <option value="1" @selected(! isset($wire) && ($val === true || (string) $val === '1'))>Yes</option>
            <option value="0" @selected(! isset($wire) && ($val === false || (string) $val === '0'))>No</option>
        </select>
        @break
    @case('long_text')
        <textarea {!! $bind !!} rows="2" class="{{ $cls }}" style="border-color: var(--border);">{{ isset($wire) ? '' : $val }}</textarea>
        @break
    @default
        <input {!! $bind !!} type="{{ ['number' => 'number', 'date' => 'date'][$field->input_type] ?? 'text' }}" @if ($field->input_type === 'number') step="any" @endif
            @unless (isset($wire)) value="{{ $field->input_type === 'date' && $val ? \Illuminate\Support\Carbon::parse($val)->toDateString() : $val }}" @endunless
            class="{{ $cls }}" style="border-color: var(--border);">
@endswitch
