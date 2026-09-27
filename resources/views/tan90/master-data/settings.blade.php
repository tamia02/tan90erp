<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">Master Data Settings</h2>
  </x-slot>

  <div class="max-w-5xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
      <p class="text-sm" style="color: var(--text-secondary);">GST, Maps and SMTP credentials are encrypted at rest and never shown in clear text once saved.</p>
      <button type="submit" form="settings-form" class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium text-white shrink-0" style="background: var(--brand);">Save {{ ucfirst($group) }}</button>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
      <div class="rounded-lg border p-3" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex sm:flex-col gap-1">
          @foreach ($groups as $g)
            <a href="{{ route('tan90.master-data.settings.edit', $g) }}" class="text-sm px-2.5 py-1.5 rounded-lg" style="{{ $g === $group ? 'background: var(--brand-bg); color: var(--brand); font-weight: 600;' : 'color: var(--text-secondary);' }}">{{ ucfirst($g) }}</a>
          @endforeach
        </div>
      </div>
      <div class="sm:col-span-3 rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h3 class="font-semibold text-sm mb-1" style="color: var(--text-primary);">{{ ucfirst($group) }}</h3>
        <p class="text-xs mb-3" style="color: var(--text-muted);">Changes are written to tan90_module_settings.</p>
        <form id="settings-form" method="POST" action="{{ route('tan90.master-data.settings.update', $group) }}" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          @csrf
          @foreach ($fields as $field)
            @php($value = $values[$field['key']] ?? null)
            @if ($field['type'] === 'checkbox')
              <label class="flex items-center gap-2 text-sm sm:col-span-2">
                <input type="checkbox" name="{{ $field['key'] }}" value="1" class="w-4 h-4" @checked($value === '1')>
                <span style="color: var(--text-primary);">{{ $field['label'] }} — Enabled</span>
              </label>
            @elseif ($field['type'] === 'select')
              <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">{{ $field['label'] }}</span>
                <select name="{{ $field['key'] }}" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                  @foreach ($field['options'] as $option)
                    <option value="{{ $option }}" @selected($value === $option)>{{ $option }}</option>
                  @endforeach
                </select>
              </label>
            @elseif ($field['type'] === 'secret')
              <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">{{ $field['label'] }} <span class="text-xs" style="color: var(--text-muted);">— Encrypted / masked</span></span>
                <input type="password" name="{{ $field['key'] }}" value="" placeholder="{{ $value ? 'Leave blank to keep current' : 'Not set' }}" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
              </label>
            @else
              <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">{{ $field['label'] }}</span>
                <input type="{{ $field['type'] }}" name="{{ $field['key'] }}" value="{{ $value }}" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
              </label>
            @endif
          @endforeach
        </form>
      </div>
    </div>
  </div>
</x-app-layout>
