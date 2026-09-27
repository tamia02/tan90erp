<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">Import Preview</h2>
  </x-slot>

  <div class="max-w-5xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
      <div>
        <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted);">{{ $entity['title'] }}</p>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ $job->total_rows }} rows · {{ $job->valid_rows }} valid · {{ $job->invalid_rows }} invalid · {{ $job->duplicate_rows }} duplicate</p>
      </div>
      <div class="flex gap-2">
        <a href="{{ route('tan90.master-data.import.rejected-csv', $job->id) }}" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">Rejected Rows CSV</a>
        @if ($job->result === 'previewed')
          <form method="POST" action="{{ route('tan90.master-data.import.commit', $job->id) }}">
            @csrf
            <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Import {{ $job->valid_rows }} Valid Rows</button>
          </form>
        @endif
      </div>
    </div>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
              <th class="px-4 py-2.5 font-medium">Row</th><th class="px-4 py-2.5 font-medium">Key</th><th class="px-4 py-2.5 font-medium">Status</th><th class="px-4 py-2.5 font-medium">Errors</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($rows as $row)
              <tr style="border-top: 1px solid var(--border);">
                <td class="px-4 py-2.5" style="color: var(--text-primary);">{{ $row->row_number }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $row->source_row_key }}</td>
                <td class="px-4 py-2.5">@include('tan90.master-data.partials.status-badge', ['value' => $row->status])</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $row->errors ? collect($row->errors)->flatten()->implode('; ') : '—' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $rows->links() }}</div>
    </div>
  </div>
</x-app-layout>
