<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-5xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Access Control / Simulator</div>
            <h1 class="text-2xl font-bold">Access Simulator</h1>
            <p class="access-muted text-sm mt-1">Pick a person and a permission to see exactly why the system would allow or deny it - the same engine the app itself uses on every request, not a separate copy of the rules.</p>
        </div>

        <form method="get" action="{{ route('access.simulator.index') }}" class="access-card p-5 grid md:grid-cols-[1fr_1fr_auto] gap-3 items-end">
            <div>
                <label class="text-xs font-bold access-muted uppercase">Person</label>
                <select class="access-input mt-1" name="user_id" required>
                    <option value="">Select a person</option>
                    @foreach ($people as $person)
                        <option value="{{ $person->id }}" @selected($subject?->id === $person->id)>{{ $person->name }} ({{ $person->email }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs font-bold access-muted uppercase">Permission</label>
                <select class="access-input mt-1" name="permission_key" required>
                    <option value="">Select a permission</option>
                    @foreach ($permissions as $perm)
                        <option value="{{ $perm->key }}" @selected($permission?->key === $perm->key)>{{ $perm->module }} / {{ $perm->label }}</option>
                    @endforeach
                </select>
            </div>
            <button class="access-btn access-btn-primary">Simulate</button>
        </form>

        @if ($steps)
            @php($finalStep = collect($steps)->last())
            <div class="access-card p-5" style="border-color: {{ $finalStep['status'] === 'allow' ? '#147d4f' : '#b42318' }}; border-width: 2px;">
                <h2 class="font-bold mb-1">Result: {{ $subject->name }} {{ $finalStep['status'] === 'allow' ? 'CAN' : 'CANNOT' }} "{{ $permission->label }}"</h2>
                <p class="access-muted text-sm">{{ $finalStep['detail'] }}</p>
            </div>

            <div class="access-card p-5">
                <h2 class="font-bold mb-4">Decision trace</h2>
                <div class="space-y-3">
                    @foreach ($steps as $i => $step)
                        <div class="flex gap-3">
                            <div class="shrink-0 w-7 h-7 rounded-full grid place-items-center text-xs font-bold text-white"
                                 style="background: {{ $step['status'] === 'allow' ? '#147d4f' : ($step['status'] === 'deny' ? '#b42318' : '#8a97a0') }};">
                                {{ $i + 1 }}
                            </div>
                            <div class="flex-1 pb-3" style="border-bottom: 1px solid #edf1ee;">
                                <div class="font-semibold text-sm">{{ $step['label'] }}</div>
                                <div class="text-sm access-muted mt-0.5">{{ $step['detail'] }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif ($subject || $permission)
            <div class="access-card p-5 text-sm access-muted">Pick both a person and a permission to run the simulation.</div>
        @endif
    </div>
</x-app-layout>
