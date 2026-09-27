<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">New Recipe</h2>
  </x-slot>

  <div class="max-w-2xl mx-auto">
    <div class="flex items-center justify-end mb-5">
      <a href="{{ route('tan90.brc.recipes.index') }}" class="text-sm font-medium" style="color: var(--brand);">← Cancel</a>
    </div>

    <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
      <form method="POST" action="{{ route('tan90.brc.recipes.store') }}">
        @csrf
        <div class="grid grid-cols-1 gap-3">
          <label class="flex flex-col gap-1.5 text-sm">
            <span class="font-medium" style="color: var(--text-primary);">Recipe Code</span>
            <input type="text" name="code" value="{{ old('code') }}" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" required>
            @error('code')<span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span>@enderror
          </label>
          <label class="flex flex-col gap-1.5 text-sm">
            <span class="font-medium" style="color: var(--text-primary);">Finished Good</span>
            <select name="tan90_finished_good_id" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" required>
              <option value="">Select finished good</option>
              @foreach (\App\Models\Tan90\BomRecipeCosting\FinishedGood::active()->orderBy('name')->get() as $fg)
                <option value="{{ $fg->id }}" @selected(old('tan90_finished_good_id') == $fg->id)>{{ $fg->name }}</option>
              @endforeach
            </select>
            @error('tan90_finished_good_id')<span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span>@enderror
          </label>
          <label class="flex flex-col gap-1.5 text-sm">
            <span class="font-medium" style="color: var(--text-primary);">Name</span>
            <input type="text" name="name" value="{{ old('name') }}" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" required>
            @error('name')<span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span>@enderror
          </label>
          <label class="flex flex-col gap-1.5 text-sm">
            <span class="font-medium" style="color: var(--text-primary);">Formula Tolerance % (Optional)</span>
            <input type="number" step="0.01" name="formula_tolerance_percent" value="{{ old('formula_tolerance_percent', 0.5) }}" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
          </label>
        </div>
        <div class="flex justify-end mt-4">
          <button type="submit" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Create Recipe</button>
        </div>
      </form>
    </div>
  </div>
</x-app-layout>
