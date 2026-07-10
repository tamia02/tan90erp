<div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                    <th class="px-4 py-2.5 font-medium">SKU</th>
                    <th class="px-4 py-2.5 font-medium">Bin</th>
                    <th class="px-4 py-2.5 font-medium">Bucket</th>
                    <th class="px-4 py-2.5 font-medium">Qty</th>
                    <th class="px-4 py-2.5 font-medium">Timestamp</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($entries as $e)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">{{ $e->sku }}</td>
                        <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);">{{ $e->bin }}</td>
                        <td class="px-4 py-2.5 text-xs capitalize" style="color: {{ $e->bucket === 'available' ? 'var(--status-good)' : (in_array($e->bucket, ['defective', 'rejected']) ? 'var(--status-critical)' : 'var(--status-warning)') }};">{{ $e->bucket }}</td>
                        <td class="px-4 py-2.5" style="color: var(--text-primary);">{{ $e->qty }}</td>
                        <td class="px-4 py-2.5 text-xs" style="color: var(--text-muted);">{{ $e->created_at->format('d M Y, H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No stock ledger entries yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
