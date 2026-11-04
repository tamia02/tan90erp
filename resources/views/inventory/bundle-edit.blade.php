<x-app-layout>
    <div class="max-w-4xl mx-auto space-y-5">
        <a href="{{ route('inventory.bundles.show', $production) }}" class="text-sm font-semibold" style="color:var(--brand);">← Cancel and return to bundle</a>
        <section class="rounded-2xl border p-5 sm:p-7" style="background:var(--surface-3);border-color:var(--border);">
            <p class="text-xs uppercase font-semibold tracking-wide" style="color:var(--brand);">Production · Bundle</p>
            <h1 class="text-2xl font-bold mt-1" style="color:var(--text-primary);">Edit production bundle</h1>
            <p class="mt-2 text-sm" style="color:var(--text-secondary);">{{ $production->number }} · {{ $production->fgItem?->visibleNameFor(auth()->user()) }} · {{ $production->creator?->name ?: '—' }}</p>
            <p class="mt-3 text-sm" style="color:var(--text-secondary);">Stock is checked before revised quantities are saved. The original recorded timestamp remains unchanged.</p>

            <form method="POST" action="{{ route('inventory.bundles.update', $production) }}" class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
                @csrf @method('PUT')
                <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Quantity produced ({{ $production->fgItem?->unit }})</span><input name="qty_produced" type="number" min="0.001" step="0.001" required value="{{ old('qty_produced', $production->qty_produced) }}" class="rounded-lg border px-3 py-2" style="border-color:var(--border);">@error('qty_produced')<span class="text-xs" style="color:var(--status-critical);">{{ $message }}</span>@enderror</label>
                <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Assembly date</span><input name="production_date" type="date" required value="{{ old('production_date', ($production->production_date ?? $production->created_at)?->toDateString()) }}" class="rounded-lg border px-3 py-2" style="border-color:var(--border);">@error('production_date')<span class="text-xs" style="color:var(--status-critical);">{{ $message }}</span>@enderror</label>
                <label class="flex flex-col gap-1 text-sm sm:col-span-2"><span class="font-medium">Notes</span><textarea name="notes" maxlength="2000" rows="3" class="rounded-lg border px-3 py-2" style="border-color:var(--border);">{{ old('notes', $production->notes) }}</textarea>@error('notes')<span class="text-xs" style="color:var(--status-critical);">{{ $message }}</span>@enderror</label>
                <div class="sm:col-span-2 flex flex-wrap justify-end gap-3">
                    <a href="{{ route('inventory.bundles.show', $production) }}" class="rounded-lg border px-4 py-2 text-sm font-semibold" style="border-color:var(--border);color:var(--text-primary);">Cancel</a>
                    <button type="submit" class="rounded-lg px-4 py-2 text-sm font-semibold text-white" style="background:var(--brand);">Save changes</button>
                </div>
            </form>
        </section>
    </div>
</x-app-layout>
