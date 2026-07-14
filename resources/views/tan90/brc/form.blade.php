@extends('tan90.brc.layout')

@section('title', ($mode === 'edit' ? 'Edit' : 'Add') . ' ' . $config['singular'])
@section('page-title', ($mode === 'edit' ? 'Edit' : 'Add') . ' ' . $config['singular'])
@section('page-subtitle', $config['eyebrow'])

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">{{ $config['eyebrow'] }}</p>
      <h2>{{ $mode === 'edit' ? 'Edit' : 'Add' }} {{ $config['singular'] }}</h2>
    </div>
    <div class="page-actions">
      <a class="btn btn-ghost" href="{{ $mode === 'edit' ? route('tan90.brc.show', [$entity, $record->id]) : route('tan90.brc.index', $entity) }}">← Cancel</a>
    </div>
  </div>

  <section class="card">
    <div class="card-body">
      <form method="POST" action="{{ $mode === 'edit' ? route('tan90.brc.update', [$entity, $record->id]) : route('tan90.brc.store', $entity) }}">
        @csrf
        @if ($mode === 'edit') @method('PUT') @endif

        <div class="form-grid">
          @foreach ($config['fields'] as $field)
            @php($value = old($field['name'], $record->{$field['name']} ?? null))
            <label class="field {{ in_array($field['type'], ['textarea']) ? 'full' : '' }}">
              <span class="field-label">{{ $field['label'] }} {{ $field['required'] ? '' : '(Optional)' }}</span>

              @if ($field['type'] === 'textarea')
                <textarea name="{{ $field['name'] }}" @if($field['required']) required @endif>{{ $value }}</textarea>
              @elseif ($field['type'] === 'select')
                <select name="{{ $field['name'] }}" @if($field['required']) required @endif>
                  <option value="">Select {{ $field['label'] }}</option>
                  @foreach (app(\App\Services\Tan90\BomRecipeCosting\EntityRegistry::class)->fieldOptions($field) as $optionValue => $optionLabel)
                    <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
                  @endforeach
                </select>
              @else
                <input type="{{ $field['type'] === 'number' ? 'number' : ($field['type'] ?: 'text') }}"
                       name="{{ $field['name'] }}" value="{{ $value }}" @if($field['required']) required @endif>
              @endif
              @error($field['name'])<span class="field-error">{{ $message }}</span>@enderror
            </label>
          @endforeach
        </div>

        <div class="card-foot" style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px">
          <button class="btn btn-primary" type="submit">{{ $mode === 'edit' ? 'Save Changes' : 'Create Record' }}</button>
        </div>
      </form>
    </div>
  </section>
@endsection
