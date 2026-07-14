<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#0b1220">
  <title>@yield('title', 'Master Data & Configuration') · Tan90 ERP</title>
  <link rel="stylesheet" href="{{ asset('tan90-master-data/css/app.css') }}">
</head>
<body>
  <div class="app-shell">
    <div class="shell">
      <div class="mobile-overlay hidden" data-action="close-sidebar"></div>
      <aside class="sidebar">
        <div class="sidebar-brand">
          <div class="logo-mark">T90</div>
          <div class="brand-copy"><strong>Tan90 ERP</strong><span>Master Data Control</span></div>
        </div>
        <div class="sidebar-scroll">
          <div class="nav-section">
            <div class="nav-title">Overview</div>
            <a class="nav-item {{ request()->routeIs('tan90.master-data.dashboard') ? 'active' : '' }}" href="{{ route('tan90.master-data.dashboard') }}">
              <span class="nav-icon">DB</span><span class="nav-label">Command Center</span>
            </a>
            <a class="nav-item {{ request()->routeIs('tan90.master-data.approval-queue') ? 'active' : '' }}" href="{{ route('tan90.master-data.approval-queue') }}">
              <span class="nav-icon">AQ</span><span class="nav-label">Approval Queue</span>
            </a>
            <a class="nav-item {{ request()->routeIs('tan90.master-data.change-requests.*') ? 'active' : '' }}" href="{{ route('tan90.master-data.change-requests.index') }}">
              <span class="nav-icon">CR</span><span class="nav-label">Change Requests</span>
            </a>
          </div>

          @foreach (config('tan90_master_data.nav_groups', []) as $groupTitle => $slugs)
            <div class="nav-section">
              <div class="nav-title">{{ $groupTitle }}</div>
              @foreach ($slugs as $slug)
                @php($entityConfig = config("tan90_master_data.entities.$slug"))
                @continue(! $entityConfig)
                <a class="nav-item {{ request()->routeIs('tan90.master-data.index') && request()->route('entity') === $slug ? 'active' : '' }}"
                   href="{{ route('tan90.master-data.index', $slug) }}">
                  <span class="nav-icon">{{ $entityConfig['icon'] }}</span>
                  <span class="nav-label">{{ $entityConfig['title'] }}</span>
                </a>
              @endforeach
            </div>
          @endforeach

          <div class="nav-section">
            <div class="nav-title">Data Administration</div>
            <a class="nav-item {{ request()->routeIs('tan90.master-data.import.*') ? 'active' : '' }}" href="{{ route('tan90.master-data.import.index') }}">
              <span class="nav-icon">IM</span><span class="nav-label">Import / Export</span>
            </a>
            <a class="nav-item {{ request()->routeIs('tan90.master-data.data-quality.*') ? 'active' : '' }}" href="{{ route('tan90.master-data.data-quality.index') }}">
              <span class="nav-icon">DQ</span><span class="nav-label">Data Quality Center</span>
            </a>
            <a class="nav-item {{ request()->routeIs('tan90.master-data.audit-logs') ? 'active' : '' }}" href="{{ route('tan90.master-data.audit-logs') }}">
              <span class="nav-icon">AU</span><span class="nav-label">Audit Trail</span>
            </a>
            <a class="nav-item {{ request()->routeIs('tan90.master-data.permission-matrix.*') ? 'active' : '' }}" href="{{ route('tan90.master-data.permission-matrix.edit') }}">
              <span class="nav-icon">PM</span><span class="nav-label">Permission Matrix</span>
            </a>
            <a class="nav-item {{ request()->routeIs('tan90.master-data.settings.*') ? 'active' : '' }}" href="{{ route('tan90.master-data.settings.edit') }}">
              <span class="nav-icon">ST</span><span class="nav-label">System Settings</span>
            </a>
          </div>
        </div>
        <div class="sidebar-user">
          <div class="user-card">
            <div class="avatar">{{ Str::of(auth()->user()->name ?? 'U')->explode(' ')->map(fn($p) => Str::substr($p, 0, 1))->take(2)->implode('') }}</div>
            <div class="user-meta">
              <strong>{{ auth()->user()->name ?? 'User' }}</strong>
              <span>{{ auth()->user()->tan90Profile?->role?->name ?? 'No role assigned' }}</span>
            </div>
            <form method="POST" action="{{ route('logout') }}">
              @csrf
              <button class="icon-btn" type="submit" title="Logout">↗</button>
            </form>
          </div>
        </div>
      </aside>

      <section class="main-shell">
        <header class="topbar">
          <button class="icon-btn mobile-menu" data-action="toggle-sidebar" aria-label="Menu">☰</button>
          <div class="topbar-title">
            <h1>@yield('page-title', 'Master Data & Configuration')</h1>
            <p>@yield('page-subtitle', 'Enterprise Control Center')</p>
          </div>
          <div class="topbar-spacer"></div>
          <button class="icon-btn" data-action="toggle-theme" title="Toggle theme">◐</button>
        </header>
        <main class="content">
          @if (session('status'))
            <div class="card" style="margin-bottom:14px;padding:12px 16px;border-left:3px solid var(--success)">{{ session('status') }}</div>
          @endif
          @if ($errors->any())
            <div class="card" style="margin-bottom:14px;padding:12px 16px;border-left:3px solid var(--danger)">
              <strong>Please fix the following:</strong>
              <ul style="margin:6px 0 0 18px">
                @foreach ($errors->all() as $error)
                  <li>{{ $error }}</li>
                @endforeach
              </ul>
            </div>
          @endif
          @yield('content')
        </main>
      </section>

      <nav class="mobile-bottom-nav">
        <a class="bottom-nav-item {{ request()->routeIs('tan90.master-data.dashboard') ? 'active' : '' }}" href="{{ route('tan90.master-data.dashboard') }}"><strong>⌂</strong>Home</a>
        <a class="bottom-nav-item" href="{{ route('tan90.master-data.index', 'items') }}"><strong>◇</strong>Items</a>
        <a class="bottom-nav-item" href="{{ route('tan90.master-data.index', 'vendors') }}"><strong>◎</strong>Vendors</a>
        <a class="bottom-nav-item {{ request()->routeIs('tan90.master-data.approval-queue') ? 'active' : '' }}" href="{{ route('tan90.master-data.approval-queue') }}"><strong>✓</strong>Approvals</a>
        <a class="bottom-nav-item {{ request()->routeIs('tan90.master-data.settings.*') ? 'active' : '' }}" href="{{ route('tan90.master-data.settings.edit') }}"><strong>⚙</strong>Settings</a>
      </nav>
    </div>
  </div>
  <script src="{{ asset('tan90-master-data/js/app.js') }}"></script>
</body>
</html>
