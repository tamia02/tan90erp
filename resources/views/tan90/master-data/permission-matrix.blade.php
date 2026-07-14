@extends('tan90.master-data.layout')

@section('title', 'Permission Matrix')
@section('page-title', 'Permission Matrix')
@section('page-subtitle', 'Role-based capabilities and governance')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">Access Control</p>
      <h2>Permission Matrix</h2>
      <p>Toggle capabilities per role. Changes are audited and editable only by Super Admin.</p>
    </div>
    <div class="page-actions">
      @if ($canEdit)
        <button class="btn btn-primary" type="submit" form="permission-matrix-form">Save Matrix</button>
      @endif
    </div>
  </div>

  <form id="permission-matrix-form" method="POST" action="{{ route('tan90.master-data.permission-matrix.update') }}">
    @csrf
    <section class="card">
      <div class="card-head"><div><h3>Role Permissions</h3><p>Data scope is configured on the Role Master.</p></div></div>
      <div class="card-body permission-matrix">
        <table>
          <thead>
            <tr>
              <th>Role</th>
              @foreach ($permissions as $permission)
                <th>{{ $permission->label }}</th>
              @endforeach
            </tr>
          </thead>
          <tbody>
            @foreach ($roles as $role)
              <tr>
                <td><div class="cell-main">{{ $role->name }}</div></td>
                @foreach ($permissions as $permission)
                  @php($allowed = $role->permissions->firstWhere('id', $permission->id)?->pivot->allowed)
                  <td>
                    <label class="switch permission-toggle">
                      <input type="checkbox"
                             name="matrix[{{ $role->id }}][{{ $permission->id }}]"
                             value="1" @checked($allowed) @disabled(! $canEdit)>
                      <span class="switch-track"></span>
                    </label>
                  </td>
                @endforeach
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </section>
  </form>
@endsection
