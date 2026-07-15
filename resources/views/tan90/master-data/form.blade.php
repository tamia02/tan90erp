<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">{{ $mode === 'edit' ? 'Edit' : 'Add' }} {{ $config['singular'] }}</h2>
  </x-slot>

  <div class="max-w-3xl mx-auto">
    <div class="flex items-center justify-between mb-5">
      <p class="text-sm" style="color: var(--text-secondary);">{{ $mode === 'edit' ? 'Update ' . ($record->{$config['code']} ?? $record->{$config['primary']} ?? 'record') : 'Create a new governed ' . strtolower($config['singular']) . ' record.' }}</p>
      <a href="{{ $mode === 'edit' ? route('tan90.master-data.show', [$entity, $record->id]) : route('tan90.master-data.index', $entity) }}" class="text-sm font-medium shrink-0" style="color: var(--brand);">← Cancel</a>
    </div>

    @if ($mode === 'edit' && $record->approval_status === 'approved' && array_intersect(array_column($config['fields'], 'name'), $config['critical_fields']))
      <div class="rounded-lg border p-3 mb-4 text-sm" style="background: var(--status-warning-bg); border-color: var(--status-warning); color: var(--status-warning);">
        This record is approved. Changing a critical field ({{ implode(', ', $config['critical_fields']) }}) will open a change request instead of saving directly - it takes effect once an approver reviews it.
      </div>
    @endif

    <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
      <form method="POST" action="{{ $mode === 'edit' ? route('tan90.master-data.update', [$entity, $record->id]) : route('tan90.master-data.store', $entity) }}">
        @csrf
        @if ($mode === 'edit') @method('PUT') @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          @foreach ($config['fields'] as $field)
            @php($value = old($field['name'], $record->{$field['name']} ?? null))
            @php($autoNumbered = $mode === 'create' && ($hasNumberSeries ?? false) && $field['name'] === $config['code'])
            @php($isRequired = $field['required'] && ! $autoNumbered)
            <label class="flex flex-col gap-1.5 text-sm {{ $field['type'] === 'textarea' ? 'sm:col-span-2' : '' }}">
              <span class="font-medium" style="color: var(--text-primary);">
                {{ $field['label'] }}
                {{ $autoNumbered ? '' : ($field['required'] ? '' : ' (Optional)') }}
                @if ($autoNumbered)<span class="text-xs" style="color: var(--text-muted);"> — Auto-generated if left blank</span>@endif
              </span>

              @if ($field['type'] === 'textarea')
                <textarea name="{{ $field['name'] }}" rows="3" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" @if($isRequired) required @endif>{{ $value }}</textarea>
              @elseif ($field['type'] === 'select')
                <select name="{{ $field['name'] }}" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" @if($isRequired) required @endif>
                  <option value="">Select {{ $field['label'] }}</option>
                  @foreach (app(\App\Services\Tan90\MasterData\EntityRegistry::class)->fieldOptions($field) as $optionValue => $optionLabel)
                    <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
                  @endforeach
                </select>
              @else
                <input type="{{ $field['type'] === 'number' ? 'number' : ($field['type'] ?: 'text') }}"
                       name="{{ $field['name'] }}" value="{{ $value }}" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" @if($isRequired) required @endif>
              @endif
              @error($field['name'])<span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span>@enderror
            </label>
          @endforeach

          @if ($mode === 'edit')
            <label class="flex flex-col gap-1.5 text-sm sm:col-span-2">
              <span class="font-medium" style="color: var(--text-primary);">Change Reason <span class="text-xs" style="color: var(--text-muted);">— Required only if a critical field changed</span></span>
              <input type="text" name="change_reason" placeholder="Why is this change needed?" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
            </label>
          @endif
        </div>

        <div class="flex justify-end mt-4">
          <button type="submit" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">{{ $mode === 'edit' ? 'Save Changes' : 'Create Record' }}</button>
        </div>
      </form>
    </div>
  </div>
</x-app-layout>
