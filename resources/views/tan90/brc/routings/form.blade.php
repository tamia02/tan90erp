@extends('tan90.brc.layout')

@section('title', 'New Routing')
@section('page-title', 'New Routing')
@section('page-subtitle', 'Manufacturing')

@section('content')
  <div class="page-head">
    <div class="page-title"><h2>New Routing</h2></div>
    <div class="page-actions"><a class="btn btn-ghost" href="{{ route('tan90.brc.routings.index') }}">← Cancel</a></div>
  </div>

  <section class="card">
    <div class="card-body">
      <form method="POST" action="{{ route('tan90.brc.routings.store') }}">
        @csrf
        <div class="form-grid">
          <label class="field">
            <span class="field-label">Routing Code</span>
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
          </label>
        </div>
        <div class="card-foot" style="display:flex;justify-content:flex-end;margin-top:14px">
          <button class="btn btn-primary" type="submit">Create Routing</button>
        </div>
      </form>
    </div>
  </section>
@endsection
