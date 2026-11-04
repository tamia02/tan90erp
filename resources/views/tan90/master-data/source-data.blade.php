<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-xl font-semibold" style="color: var(--text-primary);">Imported source data</h2>
            <a href="{{ route('tan90.master-data.dashboard') }}" class="text-sm font-medium" style="color: var(--brand);">Back to Master Data</a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-5">
        <section class="rounded-xl border p-4 sm:p-5" style="background: var(--surface-3); border-color: var(--border);">
            <p class="text-sm" style="color: var(--text-secondary);">The source workbook rows are retained in encrypted form. This screen shows the normalized address and contact fields and their links to existing master records.</p>
            <div class="mt-4 flex flex-wrap gap-2">
                <a href="{{ route('tan90.master-data.source-data', ['kind' => 'addresses']) }}" class="rounded-lg px-3 py-2 text-sm font-medium {{ $kind === 'addresses' ? 'text-white' : '' }}" style="background: {{ $kind === 'addresses' ? 'var(--brand)' : 'var(--surface-2)' }}; color: {{ $kind === 'addresses' ? '#fff' : 'var(--text-primary)' }};">Addresses</a>
                <a href="{{ route('tan90.master-data.source-data', ['kind' => 'contacts']) }}" class="rounded-lg px-3 py-2 text-sm font-medium" style="background: {{ $kind === 'contacts' ? 'var(--brand)' : 'var(--surface-2)' }}; color: {{ $kind === 'contacts' ? '#fff' : 'var(--text-primary)' }};">Contact people</a>
            </div>
            <form method="GET" class="mt-4 flex gap-2">
                <input type="hidden" name="kind" value="{{ $kind }}">
                <input name="q" value="{{ request('q') }}" placeholder="Search partner, city, email..." class="min-w-0 flex-1 rounded-lg border px-3 py-2 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);">
                <button class="rounded-lg px-4 py-2 text-sm font-medium text-white" style="background: var(--brand);">Search</button>
            </form>
        </section>

        <section class="overflow-x-auto rounded-xl border" style="background: var(--surface-3); border-color: var(--border);">
            <table class="w-full min-w-[850px] text-left text-sm">
                <thead style="color: var(--text-muted);"><tr class="border-b" style="border-color: var(--border);">
                    <th class="px-4 py-3">Partner</th>
                    @if ($kind === 'addresses')
                        <th class="px-4 py-3">Address</th><th class="px-4 py-3">City / state</th><th class="px-4 py-3">Postal code</th><th class="px-4 py-3">Source ID</th>
                    @else
                        <th class="px-4 py-3">Contact person</th><th class="px-4 py-3">Email</th><th class="px-4 py-3">Phone</th><th class="px-4 py-3">Role</th>
                    @endif
                    <th class="px-4 py-3">Master link</th>
                </tr></thead>
                <tbody>
                    @forelse ($records as $record)
                        <tr class="border-b last:border-0" style="border-color: var(--border); color: var(--text-primary);">
                            <td class="px-4 py-3"><div class="font-medium">{{ $record->partner_name ?: 'Unnamed partner' }}</div><div class="text-xs" style="color: var(--text-muted);">{{ ucfirst($record->partner_type) }}</div></td>
                            @if ($kind === 'addresses')
                                <td class="px-4 py-3">{{ collect([$record->attention, $record->address, $record->street2])->filter()->implode(', ') ?: '—' }}</td>
                                <td class="px-4 py-3">{{ collect([$record->city, $record->state, $record->country])->filter()->implode(', ') ?: '—' }}</td>
                                <td class="px-4 py-3">{{ $record->postal_code ?: '—' }}</td>
                                <td class="px-4 py-3">{{ $record->source_address_id }}</td>
                            @else
                                <td class="px-4 py-3">{{ $record->display_name ?: collect([$record->first_name, $record->last_name])->filter()->implode(' ') ?: '—' }}</td>
                                <td class="px-4 py-3">{{ $record->email ?: '—' }}</td>
                                <td class="px-4 py-3">{{ collect([$record->phone, $record->mobile_phone])->filter()->implode(' / ') ?: '—' }}</td>
                                <td class="px-4 py-3">{{ collect([$record->designation, $record->department])->filter()->implode(' · ') ?: '—' }}</td>
                            @endif
                            <td class="px-4 py-3">{{ $record->canonical_id ? ucfirst($record->canonical_type).' #'.$record->canonical_id : 'Unmatched' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center" style="color: var(--text-muted);">No imported records found.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="p-4">{{ $records->links() }}</div>
        </section>

        <section class="rounded-xl border p-4 sm:p-5" style="background: var(--surface-3); border-color: var(--border);">
            <h3 class="mb-3 font-semibold" style="color: var(--text-primary);">Source workbook coverage</h3>
            <div class="overflow-x-auto"><table class="w-full min-w-[520px] text-left text-sm">
                <thead style="color: var(--text-muted);"><tr><th class="py-2">Workbook</th><th class="py-2">Data set</th><th class="py-2">Rows</th><th class="py-2">Linked</th></tr></thead>
                <tbody>@foreach ($summary as $source)
                    <tr class="border-t" style="border-color: var(--border); color: var(--text-primary);"><td class="py-2">{{ $source->source_file }}</td><td class="py-2">{{ str_replace('_', ' ', ucfirst($source->dataset)) }}</td><td class="py-2">{{ number_format($source->total) }}</td><td class="py-2">{{ number_format($source->linked) }}</td></tr>
                @endforeach</tbody>
            </table></div>
        </section>
    </div>
</x-app-layout>
