@extends('tan90.master-data.layout')

@section('title', 'Import Preview')
@section('page-title', 'Import Preview')
@section('page-subtitle', $job->original_filename)

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">{{ $entity['title'] }}</p>
      <h2>{{ $job->original_filename }}</h2>
      <p>{{ $job->total_rows }} rows · {{ $job->valid_rows }} valid · {{ $job->invalid_rows }} invalid · {{ $job->duplicate_rows }} duplicate</p>
    </div>
    <div class="page-actions">
      <a class="btn btn-ghost" href="{{ route('tan90.master-data.import.rejected-csv', $job->id) }}">⇩ Rejected Rows CSV</a>
      @if ($job->result === 'previewed')
        <form method="POST" action="{{ route('tan90.master-data.import.commit', $job->id) }}">
          @csrf
          <button class="btn btn-primary" type="submit">Import {{ $job->valid_rows }} Valid Rows</button>
        </form>
      @endif
    </div>
  </div>

  <div class="table-wrap">
    <table>
      <thead><tr><th>Row</th><th>Key</th><th>Status</th><th>Errors</th></tr></thead>
      <tbody>
        @foreach ($rows as $row)
          <tr>
            <td>{{ $row->row_number }}</td>
            <td class="code">{{ $row->source_row_key }}</td>
            <td>@include('tan90.master-data.partials.status-badge', ['value' => $row->status])</td>
            <td>{{ $row->errors ? collect($row->errors)->flatten()->implode('; ') : '—' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <div style="margin-top:12px">{{ $rows->links() }}</div>
@endsection
