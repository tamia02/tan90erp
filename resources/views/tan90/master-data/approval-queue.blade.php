<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">Approval Queue</h2>
  </x-slot>

  <div class="max-w-5xl mx-auto">
    <p class="text-sm mb-5" style="color: var(--text-secondary);">Maker-checker workspace for draft, pending and review-stage master records.</p>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);"><div class="text-xs" style="color: var(--text-muted);">Total Pending</div><div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ $rows->count() }}</div></div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);"><div class="text-xs" style="color: var(--text-muted);">Draft</div><div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ $rows->where('status', 'draft')->count() }}</div></div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);"><div class="text-xs" style="color: var(--text-muted);">In Review</div><div class="text-2xl font-semibold mt-1" style="color: var(--status-warning);">{{ $rows->where('status', 'review')->count() }}</div></div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);"><div class="text-xs" style="color: var(--text-muted);">Pending</div><div class="text-2xl font-semibold mt-1" style="color: var(--status-warning);">{{ $rows->where('status', 'pending')->count() }}</div></div>
    </div>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
              <th class="px-4 py-2.5 font-medium">Record</th><th class="px-4 py-2.5 font-medium">Module</th><th class="px-4 py-2.5 font-medium">Status</th><th class="px-4 py-2.5 font-medium">Updated</th><th class="px-4 py-2.5 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($rows as $row)
              <tr style="border-top: 1px solid var(--border);">
                <td class="px-4 py-2.5">
                  <a href="{{ route('tan90.master-data.show', [$row['slug'], $row['id']]) }}" class="font-medium" style="color: var(--brand);">{{ $row['name'] }}</a>
                  <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $row['code'] }}</div>
                </td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $row['module'] }}</td>
                <td class="px-4 py-2.5">@include('tan90.master-data.partials.status-badge', ['value' => $row['status']])</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ optional($row['updated_at'])->format('d M Y, H:i') }}</td>
                <td class="px-4 py-2.5 text-right">
                  <div class="flex items-center justify-end gap-2">
                    <form method="POST" action="{{ route('tan90.master-data.approve', [$row['slug'], $row['id']]) }}">
                      @csrf <button type="submit" class="text-xs font-medium" style="color: var(--status-good);">Approve</button>
                    </form>
                    <form method="POST" action="{{ route('tan90.master-data.reject', [$row['slug'], $row['id']]) }}">
                      @csrf <button type="submit" class="text-xs font-medium" style="color: var(--status-critical);">Reject</button>
                    </form>
                  </div>
                </td>
              </tr>
            @empty
              <tr><td colspan="5" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No pending approvals — all master records are currently clear.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</x-app-layout>
