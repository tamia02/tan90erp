<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">Master Change Requests</h2>
  </x-slot>

  <div class="max-w-5xl mx-auto">
    <p class="text-sm mb-5" style="color: var(--text-secondary);">Every critical-field edit on an approved record opens a request here instead of saving directly.</p>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
              <th class="px-4 py-2.5 font-medium">Request No.</th><th class="px-4 py-2.5 font-medium">Entity</th><th class="px-4 py-2.5 font-medium">Record</th><th class="px-4 py-2.5 font-medium">Fields</th><th class="px-4 py-2.5 font-medium">Requested By</th><th class="px-4 py-2.5 font-medium">Priority</th><th class="px-4 py-2.5 font-medium">Status</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($requests as $cr)
              <tr style="border-top: 1px solid var(--border);">
                <td class="px-4 py-2.5"><a href="{{ route('tan90.master-data.change-requests.show', $cr->id) }}" class="font-medium" style="color: var(--brand);">{{ $cr->request_no }}</a></td>
                <td class="px-4 py-2.5" style="color: var(--text-primary);">{{ $cr->entity_type }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $cr->record_code }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ implode(', ', array_keys($cr->proposed_changes)) }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $cr->requester?->name }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $cr->priority }}</td>
                <td class="px-4 py-2.5">@include('tan90.master-data.partials.status-badge', ['value' => $cr->approval_status])</td>
              </tr>
            @empty
              <tr><td colspan="7" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No change requests.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $requests->links() }}</div>
    </div>
  </div>
</x-app-layout>
