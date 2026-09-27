<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">{{ $mode === 'edit' ? 'Edit' : 'Add' }} {{ $config['singular'] }}</h2>
  </x-slot>

  <div class="max-w-3xl mx-auto">
    <div class="flex items-center justify-between mb-5">
      <p class="text-sm" style="color: var(--text-secondary);">{{ $config['eyebrow'] }}</p>
      <a href="{{ $mode === 'edit' ? route('tan90.brc.show', [$entity, $record->id]) : route('tan90.brc.index', $entity) }}" class="text-sm font-medium" style="color: var(--brand);">← Cancel</a>
    </div>

    <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
      <form method="POST" action="{{ $mode === 'edit' ? route('tan90.brc.update', [$entity, $record->id]) : route('tan90.brc.store', $entity) }}">
        @csrf
        @if ($mode === 'edit') @method('PUT') @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          @foreach ($config['fields'] as $field)
            @php($value = old($field['name'], $record->{$field['name']} ?? null))
            <label class="flex flex-col gap-1.5 text-sm {{ $field['type'] === 'textarea' ? 'sm:col-span-2' : '' }}">
              <span class="font-medium" style="color: var(--text-primary);">{{ $field['label'] }} {{ $field['required'] ? '' : '(Optional)' }}</span>

              @if ($field['type'] === 'textarea')
                <textarea name="{{ $field['name'] }}" rows="3" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" @if($field['required']) required @endif>{{ $value }}</textarea>
              @elseif ($field['type'] === 'select')
                <select name="{{ $field['name'] }}" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" @if($field['required']) required @endif>
                  <option value="">Select {{ $field['label'] }}</option>
                  @foreach (app(\App\Services\Tan90\BomRecipeCosting\EntityRegistry::class)->fieldOptions($field) as $optionValue => $optionLabel)
                    <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
                  @endforeach
                </select>
              @else
                <input type="{{ $field['type'] === 'number' ? 'number' : ($field['type'] ?: 'text') }}"
                       name="{{ $field['name'] }}" value="{{ $value }}" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" @if($field['required']) required @endif>
              @endif
              @error($field['name'])<span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span>@enderror
            </label>
          @endforeach
        </div>

        <div class="flex justify-end mt-4">
          <button type="submit" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">{{ $mode === 'edit' ? 'Save Changes' : 'Create Record' }}</button>
        </div>
      </form>
    </div>
  </div>
</x-app-layout>
