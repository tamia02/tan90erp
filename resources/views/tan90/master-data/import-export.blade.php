@extends('tan90.master-data.layout')

@section('title', 'Import / Export Center')
@section('page-title', 'Import / Export Center')
@section('page-subtitle', 'CSV-based master data administration')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">Data Administration</p>
      <h2>Import / Export Center</h2>
      <p>Upload CSV masters, preview validation and export clean or rejected rows.</p>
    </div>
  </div>

  <div class="stepper">
    <div class="step active"><span class="step-number">1</span>Upload</div>
    <div class="step"><span class="step-number">2</span>Preview</div>
    <div class="step"><span class="step-number">3</span>Validate</div>
    <div class="step"><span class="step-number">4</span>Import</div>
  </div>

  <section class="card">
    <div class="card-body">
      <form method="POST" action="{{ route('tan90.master-data.import.upload') }}" enctype="multipart/form-data" class="form-grid">
        @csrf
        <label class="field">
          <span class="field-label">Entity</span>
          <select name="entity" required>
            <option value="">Select entity</option>
            @foreach ($importableEntities as $slug => $entityConfig)
              <option value="{{ $slug }}">{{ $entityConfig['title'] }}</option>
            @endforeach
          </select>
        </label>
        <label class="field">
          <span class="field-label">CSV File <span class="optional">Max 10 MB</span></span>
          <input type="file" name="file" accept=".csv,text/csv" required>
        </label>
        <div class="full" style="margin-top:6px">
          <button class="btn btn-primary" type="submit">Upload & Preview</button>
        </div>
      </form>
    </div>
  </section>

  <section class="card" style="margin-top:15px">
    <div class="card-head"><div><h3>Import History</h3><p>Recent jobs and validation outcomes</p></div></div>
    <div class="card-body" style="padding:0">
      <div class="table-wrap" style="border:0;border-radius:0">
        <table>
          <thead><tr><th>File</th><th>Entity</th><th>Rows</th><th>Valid</th><th>Invalid</th><th>Duplicates</th><th>Result</th><th>Started By</th></tr></thead>
          <tbody>
            @forelse ($jobs as $job)
              <tr>
                <td><a class="cell-main" href="{{ route('tan90.master-data.import.show', $job->id) }}">{{ $job->original_filename }}</a></td>
                <td>{{ $job->entity_type }}</td>
                <td>{{ $job->total_rows }}</td>
                <td>{{ $job->valid_rows }}</td>
                <td>{{ $job->invalid_rows }}</td>
                <td>{{ $job->duplicate_rows }}</td>
                <td>@include('tan90.master-data.partials.status-badge', ['value' => $job->result])</td>
                <td>{{ $job->startedBy?->name }}</td>
              </tr>
            @empty
              <tr><td colspan="8"><div class="empty-state"><div><div class="empty-icon">IM</div><h3>No imports yet</h3></div></div></td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </section>
@endsection
