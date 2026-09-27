<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">Import / Export Center</h2>
  </x-slot>

  <div class="max-w-5xl mx-auto">
    <p class="text-sm mb-5" style="color: var(--text-secondary);">Upload CSV masters, preview validation and export clean or rejected rows.</p>

    <div class="rounded-lg border p-4 mb-6" style="background: var(--surface-3); border-color: var(--border);">
      <form method="POST" action="{{ route('tan90.master-data.import.upload') }}" enctype="multipart/form-data" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        @csrf
        <label class="flex flex-col gap-1.5 text-sm">
          <span class="font-medium" style="color: var(--text-primary);">Entity</span>
          <select name="entity" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" required>
            <option value="">Select entity</option>
            @foreach ($importableEntities as $slug => $entityConfig)
              <option value="{{ $slug }}">{{ $entityConfig['title'] }}</option>
            @endforeach
          </select>
        </label>
        <label class="flex flex-col gap-1.5 text-sm">
          <span class="font-medium" style="color: var(--text-primary);">CSV File <span class="text-xs" style="color: var(--text-muted);">Max 10 MB</span></span>
          <input type="file" name="file" accept=".csv,text/csv" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" required>
        </label>
        <div class="sm:col-span-2">
          <button type="submit" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Upload & Preview</button>
        </div>
      </form>
    </div>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
      <div class="px-4 pt-3">
        <h3 class="font-semibold text-sm" style="color: var(--text-primary);">Import History</h3>
        <p class="text-xs mb-2" style="color: var(--text-muted);">Recent jobs and validation outcomes</p>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
              <th class="px-4 py-2.5 font-medium">File</th><th class="px-4 py-2.5 font-medium">Entity</th><th class="px-4 py-2.5 font-medium">Rows</th><th class="px-4 py-2.5 font-medium">Valid</th><th class="px-4 py-2.5 font-medium">Invalid</th><th class="px-4 py-2.5 font-medium">Duplicates</th><th class="px-4 py-2.5 font-medium">Result</th><th class="px-4 py-2.5 font-medium">Started By</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($jobs as $job)
              <tr style="border-top: 1px solid var(--border);">
                <td class="px-4 py-2.5"><a href="{{ route('tan90.master-data.import.show', $job->id) }}" class="font-medium" style="color: var(--brand);">{{ $job->original_filename }}</a></td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $job->entity_type }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $job->total_rows }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $job->valid_rows }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $job->invalid_rows }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $job->duplicate_rows }}</td>
                <td class="px-4 py-2.5">@include('tan90.master-data.partials.status-badge', ['value' => $job->result])</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $job->startedBy?->name }}</td>
              </tr>
            @empty
              <tr><td colspan="8" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No imports yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</x-app-layout>
