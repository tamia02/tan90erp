@extends('tan90.brc.layout')

@section('title', 'New BOM')
@section('page-title', 'New BOM')
@section('page-subtitle', 'Manufacturing')

@section('content')
  <div class="page-head">
    <div class="page-title"><h2>New BOM</h2></div>
    <div class="page-actions"><a class="btn btn-ghost" href="{{ route('tan90.brc.boms.index') }}">← Cancel</a></div>
  </div>

  <section class="card">
    <div class="card-body">
      <form method="POST" action="{{ route('tan90.brc.boms.store') }}">
        @csrf
        <div class="form-grid">
          <label class="field">
            <span class="field-label">BOM Code</span>
            <input type="text" name="code" value="{{ old('code') }}" required>
            @error('code')<span class="field-error">{{ $message }}</span>@enderror
          </label>
          <label class="field">
            <span class="field-label">Finished Good</span>
            <select name="tan90_finished_good_id" required>
              <option value="">Select finished good</option>
              @foreach (\App\Models\Tan90\BomRecipeCosting\FinishedGood::active()->orderBy('name')->get() as $fg)
                <option value="{{ $fg->id }}" @selected(old('tan90_finished_good_id') == $fg->id)>{{ $fg->name }}</option>
              @endforeach
            </select>
            @error('tan90_finished_good_id')<span class="field-error">{{ $message }}</span>@enderror
          </label>
          <label class="field">
            <span class="field-label">BOM Type</span>
            <select name="bom_type" required>
              @foreach (['production', 'packaging', 'service'] as $type)
                <option value="{{ $type }}" @selected(old('bom_type') === $type)>{{ ucfirst($type) }}</option>
              @endforeach
            </select>
          </label>
        </div>
        <div class="card-foot" style="display:flex;justify-content:flex-end;margin-top:14px">
          <button class="btn btn-primary" type="submit">Create BOM</button>
        </div>
      </form>
    </div>
  </section>
@endsection
