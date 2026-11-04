<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Forge / Blast Freezers</div>
            <h1 class="text-2xl font-bold">Blast Freezer Monitor</h1>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

        <div class="access-grid">
            @forelse ($freezers as $freezer)
                @php($latest = $freezer->latestReading())
                @php($openLog = $freezer->logs->first())
                <div class="access-card p-4 space-y-2">
                    <div class="flex items-center justify-between">
                        <strong>{{ $freezer->name }}</strong>
                        <span class="access-chip" @if($latest?->is_alert) style="background:#fee2e2;color:#b91c1c" @endif>{{ str($freezer->state)->headline() }}</span>
                    </div>
                    <div class="access-muted text-sm">{{ $freezer->code }} · {{ $freezer->site?->name ?? $freezer->plant.' · Site mapping required' }} · Capacity {{ $freezer->capacity }}</div>
                    @if (!$freezer->site_id)
                        <form method="post" action="{{ route('forge.freezers.site', $freezer) }}" class="flex gap-2">
                            @csrf
                            <select class="access-input" name="site_id" required><option value="">Assign site…</option>@foreach($sites as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select>
                            <button class="access-btn">Assign</button>
                        </form>
                    @else
                    <div class="access-muted text-sm">Range {{ $freezer->threshold_temp_min }}°C to {{ $freezer->threshold_temp_max }}°C</div>

                    <div class="text-lg font-semibold" @if($latest?->is_alert) style="color:#b91c1c" @endif>
                        {{ $latest ? $latest->temperature.'°C' : 'No readings yet' }}
                        @if($latest) <span class="access-muted text-sm">as of {{ $latest->recorded_at->format('d M, H:i') }}</span> @endif
                    </div>

                    @if ($openLog)
                        <div class="access-muted text-sm">Conditioning: {{ $openLog->batch?->batch_number }} since {{ $openLog->started_at->format('d M, H:i') }}</div>
                        <form method="post" action="{{ route('forge.freezers.release', $openLog) }}">
                            @csrf
                            <button class="access-btn">Release Batch</button>
                        </form>
                    @else
                        <form method="post" action="{{ route('forge.freezers.assign', $freezer) }}" class="flex gap-2">
                            @csrf
                            <select class="access-input" name="batch_id" required>
                                <option value="">Assign batch…</option>
                                @foreach ($availableBatches->filter(fn ($batch) => (int) $batch->workOrder?->site_id === (int) $freezer->site_id) as $batch)
                                    <option value="{{ $batch->id }}">{{ $batch->batch_number }}</option>
                                @endforeach
                            </select>
                            <button class="access-btn">Assign</button>
                        </form>
                    @endif

                    <form method="post" action="{{ route('forge.freezers.readings.store', $freezer) }}" class="flex gap-2">
                        @csrf
                        <input class="access-input" type="number" step="0.01" name="temperature" placeholder="Temp °C" required>
                        <input class="access-input" type="number" step="0.01" name="humidity" placeholder="Humidity %">
                        <button class="access-btn access-btn-primary">Log Reading</button>
                    </form>
                    @endif
                </div>
            @empty
                <p class="access-muted">{{ $site ? 'No freezers are assigned to your site yet. Ask an all-site administrator to map the legacy assets.' : 'No freezers configured yet.' }}</p>
            @endforelse
        </div>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Recent Readings</h2>
            <div style="overflow-x:auto">
                <table class="access-table">
                    <thead><tr><th>Freezer</th><th>Temp</th><th>Humidity</th><th>Recorded</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($recentReadings as $reading)
                            <tr>
                                <td>{{ $reading->freezer?->name }}</td>
                                <td>{{ $reading->temperature }}°C</td>
                                <td>{{ $reading->humidity !== null ? $reading->humidity.'%' : '—' }}</td>
                                <td>{{ $reading->recorded_at->format('d M, H:i') }}</td>
                                <td>
                                    @if ($reading->is_alert)
                                        <span class="access-chip" style="background:#fee2e2;color:#b91c1c">Out of range</span>
                                    @else
                                        <span class="access-chip">Normal</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="access-muted text-center py-6">No readings recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
