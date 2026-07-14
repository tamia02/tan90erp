@extends('tan90.brc.layout')

@section('title', 'New Recipe')
@section('page-title', 'New Recipe')
@section('page-subtitle', 'Formulation')

@section('content')
  <div class="page-head">
    <div class="page-title"><h2>New Recipe</h2></div>
    <div class="page-actions"><a class="btn btn-ghost" href="{{ route('tan90.brc.recipes.index') }}">← Cancel</a></div>
  </div>

  <section class="card">
    <div class="card-body">
      <form method="POST" action="{{ route('tan90.brc.recipes.store') }}">
        @csrf
        <div class="form-grid">
          <label class="field">
            <span class="field-label">Recipe Code</span>
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
            <span class="field-label">Name</span>
            <input type="text" name="name" value="{{ old('name') }}" required>
            @error('name')<span class="field-error">{{ $message }}</span>@enderror
          </label>
          <label class="field">
            <span class="field-label">Formula Tolerance % (Optional)</span>
            <input type="number" step="0.01" name="formula_tolerance_percent" value="{{ old('formula_tolerance_percent', 0.5) }}">
          </label>
        </div>
        <div class="card-foot" style="display:flex;justify-content:flex-end;margin-top:14px">
          <button class="btn btn-primary" type="submit">Create Recipe</button>
        </div>
      </form>
    </div>
  </section>
@endsection
