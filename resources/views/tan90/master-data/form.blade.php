@extends('tan90.master-data.layout')

@section('title', ($mode === 'edit' ? 'Edit' : 'Add') . ' ' . $config['singular'])
@section('page-title', ($mode === 'edit' ? 'Edit' : 'Add') . ' ' . $config['singular'])
@section('page-subtitle', $config['eyebrow'])

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">{{ $config['eyebrow'] }}</p>
      <h2>{{ $mode === 'edit' ? 'Edit' : 'Add' }} {{ $config['singular'] }}</h2>
      <p>{{ $mode === 'edit' ? 'Update ' . ($record->{$config['code']} ?? $record->{$config['primary']} ?? 'record') : 'Create a new governed ' . strtolower($config['singular']) . ' record.' }}</p>
    </div>
    <div class="page-actions">
      <a class="btn btn-ghost" href="{{ $mode === 'edit' ? route('tan90.master-data.show', [$entity, $record->id]) : route('tan90.master-data.index', $entity) }}">← Cancel</a>
    </div>
  </div>

  @if ($mode === 'edit' && $record->approval_status === 'approved' && array_intersect(array_column($config['fields'], 'name'), $config['critical_fields']))
    <div class="card" style="margin-bottom:14px;padding:12px 16px;border-left:3px solid var(--warning)">
      This record is approved. Changing a critical field
      ({{ implode(', ', $config['critical_fields']) }}) will open a change request instead of
      saving directly - it takes effect once an approver reviews it.
    </div>
  @endif

  <section class="card">
    <div class="card-body">
      <form method="POST" action="{{ $mode === 'edit' ? route('tan90.master-data.update', [$entity, $record->id]) : route('tan90.master-data.store', $entity) }}">
        @csrf
        @if ($mode === 'edit') @method('PUT') @endif

        <div class="form-grid">
          @foreach ($config['fields'] as $field)
            @php($value = old($field['name'], $record->{$field['name']} ?? null))
            @php($autoNumbered = $mode === 'create' && ($hasNumberSeries ?? false) && $field['name'] === $config['code'])
            @php($isRequired = $field['required'] && ! $autoNumbered)
            <label class="field {{ in_array($field['type'], ['textarea']) ? 'full' : '' }}">
              <span class="field-label">
                {{ $field['label'] }}
                {{ $autoNumbered ? '' : ($field['required'] ? '' : ' (Optional)') }}
                @if ($autoNumbered)<span class="optional">Auto-generated if left blank</span>@endif
              </span>

              @if ($field['type'] === 'textarea')
                <textarea name="{{ $field['name'] }}" @if($isRequired) required @endif>{{ $value }}</textarea>
              @elseif ($field['type'] === 'select')
                <select name="{{ $field['name'] }}" @if($isRequired) required @endif>
                  <option value="">Select {{ $field['label'] }}</option>
                  @foreach (app(\App\Services\Tan90\MasterData\EntityRegistry::class)->fieldOptions($field) as $optionValue => $optionLabel)
                    <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
                  @endforeach
                </select>
              @else
                <input type="{{ $field['type'] === 'number' ? 'number' : ($field['type'] ?: 'text') }}"
                       name="{{ $field['name'] }}" value="{{ $value }}" @if($isRequired) required @endif>
              @endif
              @error($field['name'])<span class="field-error">{{ $message }}</span>@enderror
            </label>
          @endforeach

          @if ($mode === 'edit')
            <label class="field full">
              <span class="field-label">Change Reason <span class="optional">Required only if a critical field changed</span></span>
              <input type="text" name="change_reason" placeholder="Why is this change needed?">
            </label>
          @endif
        </div>

        <div class="card-foot" style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px">
          <button class="btn btn-primary" type="submit">{{ $mode === 'edit' ? 'Save Changes' : 'Create Record' }}</button>
        </div>
      </form>
    </div>
  </section>
@endsection
