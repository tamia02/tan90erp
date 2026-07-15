<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">Permission Matrix</h2>
  </x-slot>

  <div class="max-w-5xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
      <p class="text-sm" style="color: var(--text-secondary);">Toggle capabilities per role. Changes are audited and editable only by Super Admin.</p>
      @if ($canEdit)
        <button type="submit" form="permission-matrix-form" class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium text-white shrink-0" style="background: var(--brand);">Save Matrix</button>
      @endif
    </div>

    <form id="permission-matrix-form" method="POST" action="{{ route('tan90.master-data.permission-matrix.update') }}">
      @csrf
      <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="px-4 pt-3">
          <h3 class="font-semibold text-sm" style="color: var(--text-primary);">Role Permissions</h3>
          <p class="text-xs mb-2" style="color: var(--text-muted);">Data scope is configured on the Role Master.</p>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                <th class="px-4 py-2.5 font-medium">Role</th>
                @foreach ($permissions as $permission)
                  <th class="px-4 py-2.5 font-medium text-center">{{ $permission->label }}</th>
                @endforeach
              </tr>
            </thead>
            <tbody>
              @foreach ($roles as $role)
                <tr style="border-top: 1px solid var(--border);">
                  <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">{{ $role->name }}</td>
                  @foreach ($permissions as $permission)
                    @php($allowed = $role->permissions->firstWhere('id', $permission->id)?->pivot->allowed)
                    <td class="px-4 py-2.5 text-center">
                      <input type="checkbox" class="w-4 h-4" name="matrix[{{ $role->id }}][{{ $permission->id }}]" value="1" @checked($allowed) @disabled(! $canEdit)>
                    </td>
                  @endforeach
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </form>
  </div>
</x-app-layout>
