@extends('tan90.master-data.layout')

@section('title', 'System Settings')
@section('page-title', 'Master Data Settings')
@section('page-subtitle', 'Encrypted provider settings and governance policy')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">System Configuration</p>
      <h2>Master Data Settings</h2>
      <p>GST, Maps and SMTP credentials are encrypted at rest and never shown in clear text once saved.</p>
    </div>
    <div class="page-actions">
      <button class="btn btn-primary" type="submit" form="settings-form">Save {{ ucfirst($group) }}</button>
    </div>
  </div>

  <div class="settings-layout">
    <aside class="card">
      <div class="card-body settings-nav">
        @foreach ($groups as $g)
          <a href="{{ route('tan90.master-data.settings.edit', $g) }}" class="{{ $g === $group ? 'active' : '' }}">{{ ucfirst($g) }}</a>
        @endforeach
      </div>
    </aside>
    <section class="card">
      <div class="card-head"><div><h3>{{ ucfirst($group) }}</h3><p>Changes are written to tan90_module_settings.</p></div></div>
      <div class="card-body">
        <form id="settings-form" method="POST" action="{{ route('tan90.master-data.settings.update', $group) }}" class="form-grid">
          @csrf
          @foreach ($fields as $field)
            @php($value = $values[$field['key']] ?? null)
            @if ($field['type'] === 'checkbox')
              <label class="field full">
                <span class="field-label">{{ $field['label'] }}</span>
                <span class="checkbox"><input type="checkbox" name="{{ $field['key'] }}" value="1" @checked($value === '1')> Enabled</span>
              </label>
            @elseif ($field['type'] === 'select')
              <label class="field">
                <span class="field-label">{{ $field['label'] }}</span>
                <select name="{{ $field['key'] }}">
                  @foreach ($field['options'] as $option)
                    <option value="{{ $option }}" @selected($value === $option)>{{ $option }}</option>
                  @endforeach
                </select>
              </label>
            @elseif ($field['type'] === 'secret')
              <label class="field">
                <span class="field-label">{{ $field['label'] }} <span class="optional">Encrypted / masked</span></span>
                <div class="secret-field">
                  <input type="password" name="{{ $field['key'] }}" value="" placeholder="{{ $value ? 'Leave blank to keep current' : 'Not set' }}">
                </div>
              </label>
            @else
              <label class="field">
                <span class="field-label">{{ $field['label'] }}</span>
                <input type="{{ $field['type'] }}" name="{{ $field['key'] }}" value="{{ $value }}">
              </label>
            @endif
          @endforeach
        </form>
      </div>
    </section>
  </div>
@endsection
