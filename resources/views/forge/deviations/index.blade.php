<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Forge / Deviation, Rework & CAPA</div>
            <h1 class="text-2xl font-bold">Deviation, Rework & CAPA</h1>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

        <form class="access-card p-4 space-y-3" method="post" action="{{ route('forge.deviations.store') }}">
            @csrf
            <h2 class="font-bold">Open Deviation</h2>
            <div class="access-grid">
                <select class="access-input" name="work_order_id">
                    <option value="">No specific work order</option>
                    @foreach ($workOrders as $wo)
                        <option value="{{ $wo->id }}">{{ $wo->wo_number }}</option>
                    @endforeach
                </select>
                <select class="access-input" name="source_type" required>
                    <option value="process">Process</option>
                    <option value="quality">Quality</option>
                    <option value="machine">Machine</option>
                    <option value="traceability">Traceability</option>
                </select>
            </div>
            <textarea class="access-input" name="description" placeholder="Description" required></textarea>
            <textarea class="access-input" name="containment" placeholder="Immediate containment"></textarea>
            <button class="access-btn access-btn-primary">Open Deviation</button>
        </form>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Deviations</h2>
            <div class="space-y-3">
                @forelse ($deviations as $dev)
                    <details class="border rounded p-3" style="border-color:#dfe7e2">
                        <summary class="font-semibold">
                            {{ $dev->workOrder?->wo_number ?? 'General' }} — {{ str($dev->source_type)->headline() }}
                            <span class="access-chip ml-2">{{ str($dev->status)->headline() }}</span>
                        </summary>
                        <p class="mt-2 text-sm">{{ $dev->description }}</p>
                        <form class="space-y-2 mt-3" method="post" action="{{ route('forge.deviations.update', $dev) }}">
                            @csrf
                            @method('PUT')
                            <textarea class="access-input" name="root_cause" placeholder="Root cause">{{ $dev->root_cause }}</textarea>
                            <select class="access-input" name="disposition">
                                <option value="">Disposition</option>
                                @foreach (['use_as_is', 'rework', 'reject', 'return'] as $d)
                                    <option value="{{ $d }}" @selected($dev->disposition === $d)>{{ str($d)->headline() }}</option>
                                @endforeach
                            </select>
                            <textarea class="access-input" name="capa_action" placeholder="CAPA action">{{ $dev->capa_action }}</textarea>
                            <input class="access-input" name="capa_target_date" type="date" value="{{ optional($dev->capa_target_date)->format('Y-m-d') }}">
                            <textarea class="access-input" name="effectiveness_check" placeholder="Effectiveness check (required to close)">{{ $dev->effectiveness_check }}</textarea>
                            <select class="access-input" name="status" required>
                                @foreach (['open', 'investigating', 'disposed', 'capa_open', 'closed'] as $s)
                                    <option value="{{ $s }}" @selected($dev->status === $s)>{{ str($s)->headline() }}</option>
                                @endforeach
                            </select>
                            <button class="access-btn access-btn-primary">Save</button>
                        </form>
                    </details>
                @empty
                    <p class="access-muted text-sm">No deviations opened yet.</p>
                @endforelse
            </div>
            {{ $deviations->links() }}
        </div>
    </div>
</x-app-layout>
