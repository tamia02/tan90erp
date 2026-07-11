<?php

use App\Enums\Role;
use App\Models\AuditLogEntry;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    private const LOCATIONS = [
        'delhi' => [
            'name' => 'DELHI (MU)',
            'code' => 'DELHI-TAN90-MU',
            'owner' => 'Ram Prakash',
            'phone' => '+91 92056 54865',
            'address' => '96QX+3XH Pali, Haryana',
        ],
        'chennai' => [
            'name' => 'CHENNAI (MU)',
            'code' => 'CHENNAI-TAN90-MU',
            'owner' => 'Karthick',
            'phone' => '+91 89258 00797',
            'address' => '75/1A1, Poonamallee Bypass Rd, Senneer Kuppam, Chennai, Tamil Nadu 600077',
        ],
        'bhiwandi' => [
            'name' => 'Bhiwandi WH (MU)',
            'code' => 'Mumbai-TAN90-MU',
            'owner' => 'Shamim',
            'phone' => '+91 89258 46275',
            'address' => 'S.No. 90 H No 2, Vadpa, Bhiwandi, Maharashtra 421302',
        ],
    ];

    private const SKU_SAMPLES = [
        ['sku' => 'TNPM0050GMTP', 'name' => '3.4x4.4 50gm Tan90 Pouch'],
        ['sku' => 'TNPM0070GMTP', 'name' => '3.4x4.8 70gm Tan90 Pouch'],
        ['sku' => 'TNPM0100GMTP', 'name' => '3.4x5.4 100gm Tan90 Pouch'],
        ['sku' => 'TNPM0200TMP', 'name' => '5x7 200gm Tan90 Multiflex Pouch'],
        ['sku' => 'TNPM0500GMTP', 'name' => '6x9 500gm Tan90 Pouch'],
    ];

    public function with(): array
    {
        $role = auth()->user()->role;
        $realRows = AuditLogEntry::orderByDesc('created_at')->limit(60)->get();

        return [
            'role' => $role,
            'profile' => $this->profile($role),
            'events' => $this->events($role),
            'stats' => $this->stats($role),
            'locations' => self::LOCATIONS,
            'skuSamples' => self::SKU_SAMPLES,
            'realRows' => $role === Role::Admin ? $realRows : $this->filterRealRows($realRows, $role),
        ];
    }

    private function profile(Role $role): array
    {
        return match ($role) {
            Role::Guard => [
                'title' => 'Gate Activity',
                'subtitle' => 'Vehicle inward, document scan, GPS capture and SLA handoff for security.',
                'accent' => '#38bdf8',
                'locationKey' => 'bhiwandi',
                'image' => 'vehicle-entry.jpeg',
            ],
            Role::Vendor => [
                'title' => 'Vendor Submission Activity',
                'subtitle' => 'PO-linked invoice, e-way bill, LR and POD readiness for vendor users.',
                'accent' => '#a78bfa',
                'locationKey' => 'delhi',
                'image' => 'invoice-scan-1.jpeg',
            ],
            Role::StoreExec => [
                'title' => 'Unloading Desk Activity',
                'subtitle' => 'Dock assignment, box count, staging area and send-to-QC movement.',
                'accent' => '#34d399',
                'locationKey' => 'bhiwandi',
                'image' => 'lr-copy.jpeg',
            ],
            Role::Qc => [
                'title' => 'QC Activity',
                'subtitle' => 'Physical quantity, accepted, defective, rejected and hold decisions.',
                'accent' => '#f59e0b',
                'locationKey' => 'chennai',
                'image' => 'invoice-scan-2.jpeg',
            ],
            Role::StoreManager => [
                'title' => 'GRN and Stock Activity',
                'subtitle' => 'GRN conversion, bin posting, stock balance and ledger updates.',
                'accent' => '#22c55e',
                'locationKey' => 'bhiwandi',
                'image' => 'eway-bill.jpeg',
            ],
            Role::Finance => [
                'title' => 'Finance Review Activity',
                'subtitle' => 'Invoice value, accepted value, deductions and vendor claim closure.',
                'accent' => '#fb7185',
                'locationKey' => 'delhi',
                'image' => 'pod-copy.jpeg',
            ],
            Role::Admin => [
                'title' => 'Admin Control Activity',
                'subtitle' => 'All module actions, master changes, user access and exception monitoring.',
                'accent' => '#60a5fa',
                'locationKey' => 'chennai',
                'image' => 'eway-bill.jpeg',
            ],
        };
    }

    private function stats(Role $role): array
    {
        return match ($role) {
            Role::Guard => [['Open SLA', '3'], ['Scanned docs', '18'], ['Vehicles today', '9'], ['GPS captured', '100%']],
            Role::Vendor => [['Pending review', '5'], ['Docs accepted', '12'], ['Open issues', '2'], ['SKUs active', '240']],
            Role::StoreExec => [['At dock', '4'], ['Sent to QC', '7'], ['Avg unload', '42m'], ['Staging bins', '6']],
            Role::Qc => [['QC queue', '6'], ['Holds', '2'], ['Accepted qty', '5,385.600'], ['Defective', '0']],
            Role::StoreManager => [['GRNs posted', '8'], ['Ledger rows', '42'], ['Bin moves', '11'], ['Zoho queue', '7']],
            Role::Finance => [['Bills review', '5'], ['Claims open', '2'], ['Matched value', 'Rs 3.8L'], ['Deductions', 'Rs 12.4K']],
            Role::Admin => [['Roles active', '7'], ['Locations', '3'], ['SKUs', '240'], ['Exceptions', '6']],
        };
    }

    private function events(Role $role): array
    {
        return match ($role) {
            Role::Guard => [
                ['time' => '09:18', 'title' => 'Vehicle MH 04 GT 5521 scanned at Bhiwandi gate', 'detail' => 'Invoice, e-way bill and LR captured. SLA timer started for 12 hours.', 'status' => 'Documented'],
                ['time' => '09:26', 'title' => 'GPS location verified', 'detail' => 'Mumbai-TAN90-MU mapped to Shamim, Bhiwandi warehouse.', 'status' => 'Verified'],
                ['time' => '09:41', 'title' => 'Sent to unloading', 'detail' => 'PO RM 2627 0031 moved to unloading desk with 2 packaging pouch lines.', 'status' => 'Handoff'],
            ],
            Role::Vendor => [
                ['time' => '08:55', 'title' => 'Invoice TCM/INV/4471 submitted', 'detail' => 'TNPM0200TMP and TNPM0500GMTP documents uploaded before vehicle arrival.', 'status' => 'Submitted'],
                ['time' => '09:04', 'title' => 'E-way bill accepted', 'detail' => 'Readable copy matched with PO and GST details.', 'status' => 'Accepted'],
                ['time' => '09:30', 'title' => 'Correction requested', 'detail' => 'LR image asked again for sharper POD seal visibility.', 'status' => 'Action needed'],
            ],
            Role::StoreExec => [
                ['time' => '10:10', 'title' => 'Dock B2 assigned', 'detail' => 'Vehicle MH 04 GT 5521 moved from gate to unloading bay.', 'status' => 'In progress'],
                ['time' => '10:44', 'title' => 'Box count completed', 'detail' => '96 cartons counted against packaging material invoice.', 'status' => 'Done'],
                ['time' => '10:58', 'title' => 'Sent to QC', 'detail' => 'Line-level quantities released to QC queue.', 'status' => 'Handoff'],
            ],
            Role::Qc => [
                ['time' => '11:16', 'title' => 'QC sample drawn', 'detail' => 'TNPM0200TMP pouch roll checked for print, seal and roll damage.', 'status' => 'Sampling'],
                ['time' => '11:40', 'title' => 'Physical quantity updated', 'detail' => 'Accepted 690, defective 0, missing 5 posted against invoice qty 700.', 'status' => 'Recorded'],
                ['time' => '11:52', 'title' => 'QC result released', 'detail' => 'Ready for GRN posting and ledger movement.', 'status' => 'Released'],
            ],
            Role::StoreManager => [
                ['time' => '12:05', 'title' => 'GRN created for PO RM 2627 0031', 'detail' => 'Accepted quantity posted to Bhiwandi stock balance.', 'status' => 'Posted'],
                ['time' => '12:09', 'title' => 'Shelf/bin assigned', 'detail' => 'Packaging pouch roll moved to PM-BIN-04.', 'status' => 'Stored'],
                ['time' => '12:17', 'title' => 'Stock ledger updated', 'detail' => 'Ledger-backed GRN ready for Zoho staging queue.', 'status' => 'Ledger'],
            ],
            Role::Finance => [
                ['time' => '12:35', 'title' => 'Invoice value calculated', 'detail' => 'Rate and accepted quantity matched for finance review.', 'status' => 'Matched'],
                ['time' => '12:48', 'title' => 'Deduction reviewed', 'detail' => 'Missing quantity deduction added before vendor claim closure.', 'status' => 'Review'],
                ['time' => '13:05', 'title' => 'Vendor claim queued', 'detail' => 'Finance record ready for approval and export.', 'status' => 'Queued'],
            ],
            Role::Admin => [
                ['time' => '08:40', 'title' => 'Role matrix checked', 'detail' => 'Guard, vendor, unloading, QC, GRN, finance and admin routes healthy.', 'status' => 'Healthy'],
                ['time' => '09:15', 'title' => 'SKU master refreshed', 'detail' => 'Packaging Materials Pouch/Roll active SKU list aligned with control tower.', 'status' => 'Updated'],
                ['time' => '10:30', 'title' => 'Location contacts reviewed', 'detail' => 'Delhi, Chennai and Bhiwandi MU contacts visible for operations.', 'status' => 'Ready'],
            ],
        };
    }

    private function filterRealRows($rows, Role $role)
    {
        $keywords = match ($role) {
            Role::Guard => ['Gate entry', 'Security Guard'],
            Role::Vendor => ['Vendor submission', 'Vendor stock', 'Vendor User'],
            Role::StoreExec => ['Unloading', 'Store Executive'],
            Role::Qc => ['QC', 'QC User'],
            Role::StoreManager => ['GRN', 'SKU', 'Purchase Order', 'Store Manager'],
            Role::Finance => ['Vendor status', 'Finance User'],
            Role::Admin => [],
        };

        return $rows->filter(fn ($row) => collect($keywords)->contains(
            fn ($keyword) => str_contains($row->action, $keyword) || str_contains((string) $row->detail, $keyword)
        ))->values();
    }
}; ?>

<div class="space-y-5">
    <section class="rounded-2xl border overflow-hidden" style="background: linear-gradient(135deg, var(--surface-3), var(--surface-2)); border-color: var(--border);">
        <div class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-0">
            <div class="p-5 sm:p-6">
                <div class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide" style="background: {{ $profile['accent'] }}22; color: {{ $profile['accent'] }};">
                    {{ $role->moduleName() }}
                </div>
                <h1 class="text-2xl sm:text-3xl font-bold mt-4" style="color: var(--text-primary);">{{ $profile['title'] }}</h1>
                <p class="text-sm sm:text-base mt-2 max-w-3xl" style="color: var(--text-secondary);">{{ $profile['subtitle'] }}</p>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-5">
                    @foreach ($stats as [$label, $value])
                        <div class="rounded-xl border p-4" style="background: rgba(255,255,255,.04); border-color: var(--border);">
                            <div class="text-2xl font-bold" style="color: var(--text-primary);">{{ $value }}</div>
                            <div class="text-xs mt-1" style="color: var(--text-muted);">{{ $label }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="relative min-h-56 border-t lg:border-t-0 lg:border-l" style="border-color: var(--border);">
                <img src="{{ asset('tan90/evidence/'.$profile['image']) }}" alt="Document evidence preview" class="absolute inset-0 h-full w-full object-cover opacity-80">
                <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(2,6,23,.12), rgba(2,6,23,.82));"></div>
                <div class="absolute left-4 right-4 bottom-4 rounded-xl border p-3" style="background: rgba(2,6,23,.78); border-color: rgba(255,255,255,.16);">
                    @php($loc = $locations[$profile['locationKey']])
                    <div class="text-sm font-semibold text-white">{{ $loc['name'] }}</div>
                    <div class="text-xs mt-1 text-slate-300">{{ $loc['code'] }} - {{ $loc['owner'] }} - {{ $loc['phone'] }}</div>
                    <div class="text-xs mt-1 text-slate-400">{{ $loc['address'] }}</div>
                </div>
            </div>
        </div>
    </section>

    <div class="grid grid-cols-1 xl:grid-cols-[1fr_360px] gap-5">
        <section class="rounded-2xl border p-4 sm:p-5" style="background: var(--surface-3); border-color: var(--border);">
            <div class="flex items-center justify-between gap-3 mb-4">
                <div>
                    <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Role Activity Timeline</h2>
                    <p class="text-sm" style="color: var(--text-secondary);">Separate operational feed for {{ $role->label() }}.</p>
                </div>
            </div>

            <div class="space-y-3">
                @foreach ($events as $event)
                    <div class="rounded-xl border p-4 flex gap-4" style="background: var(--surface-2); border-color: var(--border);">
                        <div class="shrink-0 rounded-xl px-3 py-2 text-sm font-bold text-center" style="background: {{ $profile['accent'] }}22; color: {{ $profile['accent'] }};">{{ $event['time'] }}</div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">
                                <h3 class="font-semibold" style="color: var(--text-primary);">{{ $event['title'] }}</h3>
                                <span class="self-start rounded-full px-2.5 py-1 text-xs font-semibold" style="background: {{ $profile['accent'] }}18; color: {{ $profile['accent'] }};">{{ $event['status'] }}</span>
                            </div>
                            <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ $event['detail'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <aside class="space-y-5">
            <section class="rounded-2xl border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <h2 class="text-base font-semibold" style="color: var(--text-primary);">Location Desk</h2>
                <div class="space-y-3 mt-3">
                    @foreach ($locations as $location)
                        <div class="rounded-xl border p-3" style="border-color: var(--border); background: var(--surface-2);">
                            <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $location['name'] }}</div>
                            <div class="text-xs mt-1" style="color: var(--text-muted);">{{ $location['code'] }}</div>
                            <div class="text-xs mt-2" style="color: var(--text-secondary);">{{ $location['owner'] }} - {{ $location['phone'] }}</div>
                            <div class="text-xs mt-1" style="color: var(--text-muted);">{{ $location['address'] }}</div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="rounded-2xl border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <h2 class="text-base font-semibold" style="color: var(--text-primary);">Active Packaging SKUs</h2>
                <div class="space-y-2 mt-3">
                    @foreach ($skuSamples as $sku)
                        <div class="rounded-lg border px-3 py-2" style="border-color: var(--border);">
                            <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $sku['sku'] }}</div>
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $sku['name'] }}</div>
                        </div>
                    @endforeach
                </div>
            </section>
        </aside>
    </div>

    <section class="rounded-2xl border p-4 sm:p-5" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="text-lg font-semibold" style="color: var(--text-primary);">System Audit Trail</h2>
        <p class="text-sm mb-4" style="color: var(--text-secondary);">
            {{ $role === \App\Enums\Role::Admin ? 'Live audit entries from every role.' : 'Live audit entries related to this role.' }}
        </p>

        <div class="flex flex-col divide-y rounded-xl border overflow-hidden" style="border-color: var(--border); background: var(--surface-2);">
            @forelse ($realRows as $row)
                <a href="{{ route('activity.detail', $row) }}" wire:navigate class="px-4 py-3 flex items-center justify-between gap-3 hover:bg-black/5" style="border-color: var(--border);">
                    <div class="min-w-0">
                        <div class="text-sm font-medium truncate" style="color: var(--text-primary);">{{ $row->action }}</div>
                        @if ($row->detail)
                            <div class="text-xs mt-0.5 truncate" style="color: var(--text-muted);">{{ $row->detail }}</div>
                        @endif
                    </div>
                    <span class="text-xs shrink-0" style="color: var(--text-muted);">{{ $row->created_at->format('d M, H:i') }}</span>
                </a>
            @empty
                <div class="text-center text-sm py-8" style="color: var(--text-muted);">No live audit rows for this role yet.</div>
            @endforelse
        </div>
    </section>
</div>
