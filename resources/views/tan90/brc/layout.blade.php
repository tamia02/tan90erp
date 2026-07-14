<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#0b1220">
  <title>@yield('title', 'BOM, Recipe & Costing') · Tan90 ERP</title>
  <link rel="stylesheet" href="{{ asset('tan90-brc/css/app.css') }}">
</head>
<body>
  <div class="app-shell">
    <div class="shell">
      <div class="mobile-overlay hidden" data-action="close-sidebar"></div>
      <aside class="sidebar">
        <div class="sidebar-brand">
          <div class="logo-mark">T90</div>
          <div class="brand-copy"><strong>Tan90 ERP</strong><span>BOM, Recipe & Costing</span></div>
        </div>
        <div class="sidebar-scroll">
          <div class="nav-section">
            <div class="nav-title">Overview</div>
            <a class="nav-item {{ request()->routeIs('tan90.brc.dashboard') ? 'active' : '' }}" href="{{ route('tan90.brc.dashboard') }}">
              <span class="nav-icon">DB</span><span class="nav-label">Command Center</span>
            </a>
            <a class="nav-item {{ request()->routeIs('tan90.brc.mrp-readiness.*') ? 'active' : '' }}" href="{{ route('tan90.brc.mrp-readiness.index') }}">
              <span class="nav-icon">MR</span><span class="nav-label">MRP Readiness</span>
            </a>
          </div>

          <div class="nav-section">
            <div class="nav-title">Formulation & Manufacturing</div>
            <a class="nav-item {{ request()->routeIs('tan90.brc.recipes.*') ? 'active' : '' }}" href="{{ route('tan90.brc.recipes.index') }}">
              <span class="nav-icon">RC</span><span class="nav-label">Recipes</span>
            </a>
            <a class="nav-item {{ request()->routeIs('tan90.brc.boms.*') ? 'active' : '' }}" href="{{ route('tan90.brc.boms.index') }}">
              <span class="nav-icon">BM</span><span class="nav-label">BOM Register</span>
            </a>
            <a class="nav-item {{ request()->routeIs('tan90.brc.routings.*') ? 'active' : '' }}" href="{{ route('tan90.brc.routings.index') }}">
              <span class="nav-icon">RT</span><span class="nav-label">Routings</span>
            </a>
          </div>

          <div class="nav-section">
            <div class="nav-title">Costing</div>
            <a class="nav-item {{ request()->routeIs('tan90.brc.costing.*') ? 'active' : '' }}" href="{{ route('tan90.brc.costing.index') }}">
              <span class="nav-icon">CS</span><span class="nav-label">Cost Sheets</span>
            </a>
          </div>

          <div class="nav-section">
            <div class="nav-title">Change Control</div>
            <a class="nav-item {{ request()->routeIs('tan90.brc.eco.*') ? 'active' : '' }}" href="{{ route('tan90.brc.eco.index') }}">
              <span class="nav-icon">EC</span><span class="nav-label">Engineering Changes</span>
            </a>
          </div>

          @foreach (config('tan90_bom_recipe_costing.nav_groups', []) as $groupTitle => $slugs)
            <div class="nav-section">
              <div class="nav-title">{{ $groupTitle }}</div>
              @foreach ($slugs as $slug)
                @php($entityConfig = config("tan90_bom_recipe_costing.entities.$slug"))
                @continue(! $entityConfig)
                <a class="nav-item {{ request()->routeIs('tan90.brc.index') && request()->route('entity') === $slug ? 'active' : '' }}"
                   href="{{ route('tan90.brc.index', $slug) }}">
                  <span class="nav-icon">{{ $entityConfig['icon'] }}</span>
                  <span class="nav-label">{{ $entityConfig['title'] }}</span>
                </a>
              @endforeach
            </div>
          @endforeach

          <div class="nav-section">
            <div class="nav-title">Data Administration</div>
            <a class="nav-item {{ request()->routeIs('tan90.brc.audit-logs') ? 'active' : '' }}" href="{{ route('tan90.brc.audit-logs') }}">
              <span class="nav-icon">AU</span><span class="nav-label">Audit Trail</span>
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
            <h1>@yield('page-title', 'BOM, Recipe & Costing')</h1>
            <p>@yield('page-subtitle', 'Formulation, manufacturing and standard costing control')</p>
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
        <a class="bottom-nav-item {{ request()->routeIs('tan90.brc.dashboard') ? 'active' : '' }}" href="{{ route('tan90.brc.dashboard') }}"><strong>⌂</strong>Home</a>
        <a class="bottom-nav-item {{ request()->routeIs('tan90.brc.recipes.*') ? 'active' : '' }}" href="{{ route('tan90.brc.recipes.index') }}"><strong>RC</strong>Recipes</a>
        <a class="bottom-nav-item {{ request()->routeIs('tan90.brc.boms.*') ? 'active' : '' }}" href="{{ route('tan90.brc.boms.index') }}"><strong>BM</strong>BOMs</a>
        <a class="bottom-nav-item {{ request()->routeIs('tan90.brc.costing.*') ? 'active' : '' }}" href="{{ route('tan90.brc.costing.index') }}"><strong>CS</strong>Costing</a>
        <a class="bottom-nav-item {{ request()->routeIs('tan90.brc.mrp-readiness.*') ? 'active' : '' }}" href="{{ route('tan90.brc.mrp-readiness.index') }}"><strong>MR</strong>MRP</a>
      </nav>
    </div>
  </div>
  <script src="{{ asset('tan90-brc/js/app.js') }}"></script>
</body>
</html>
