<?php

namespace App\Http\Controllers;

use App\Models\Logistics\Attachment;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\VehicleType;
use App\Models\Outward\OutwardShipment;
use App\Models\Tan90\MasterData\Transporter;
use App\Models\ZohoSalesDocument;
use App\Services\Logistics\LogisticsService;
use App\Services\Zoho\ZohoApiGate;
use App\Services\Zoho\ZohoReadClient;
use App\Support\SiteLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LogisticsController extends Controller
{
    public function __construct(private LogisticsService $service) {}

    private function filtered(Request $request)
    {
        abort_unless($this->service->canRead($request->user()), 403);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(array_keys(Shipment::STATUSES))], 'channel' => ['nullable', Rule::in(['d2c', 'inland'])]]);

        return $this->service->visible($request->user())
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['channel'] ?? null, fn ($q, $channel) => $q->where('channel', $channel))
            ->when(trim($data['q'] ?? ''), fn ($q, $term) => $q->where(fn ($q) => $q->where('number', 'like', '%'.$term.'%')->orWhere('reference_number', 'like', '%'.$term.'%')->orWhere('provider_name', 'like', '%'.$term.'%')->orWhere('destination', 'like', '%'.$term.'%')));
    }

    public function index(Request $request)
    {
        $query = $this->filtered($request);
        $visible = $this->service->visible($request->user());

        return view('logistics.index', [
            'shipments' => $query->select(['id', 'number', 'channel', 'source_kind', 'reference_number', 'provider_name', 'source_location', 'destination', 'status', 'transporter_id', 'expected_delivery_at', 'created_at'])->with('transporter:id,name')->latest('id')->paginate(25)->withQueryString(),
            'counts' => (clone $visible)->selectRaw('status, COUNT(*) AS count')->groupBy('status')->pluck('count', 'status'),
            'overdue' => (clone $visible)->whereIn('status', ['assigned', 'in_transit', 'at_location'])->where('expected_delivery_at', '<', now())->count(),
            'canRtd' => $this->service->canMarkRtd($request->user()),
        ]);
    }

    public function create(Request $request)
    {
        abort_unless($this->service->canMarkRtd($request->user()), 403);
        $filter = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'document' => ['nullable', 'integer'], 'type' => ['nullable', Rule::in(['salesorder', 'invoice'])]]);
        $canUseZoho = $this->service->canUseZoho($request->user());
        abort_if(! $canUseZoho && ! empty($filter['document']), 403);
        $org = (string) config('services.zoho_items_pull.organization_id');
        $document = empty($filter['document']) ? null : ZohoSalesDocument::where('organization_id', $org)->whereIn('type', ['salesorder', 'invoice'])->findOrFail($filter['document']);
        $items = collect($document?->payload['line_items'] ?? [])->map(fn ($row) => ['name' => $row['name'] ?? $row['description'] ?? '', 'quantity' => $row['quantity'] ?? 1, 'unit' => $row['unit'] ?? 'NOS'])->values()->all();
        $shipping = $document?->payload['shipping_address'] ?? [];
        $destination = implode(', ', array_filter(array_intersect_key($shipping, array_flip(['address', 'city', 'state', 'zip', 'country']))));
        $sourceOrder = $document?->type === 'salesorder' ? $document->zoho_id : $document?->salesorder_id;

        return view('logistics.create', [
            'document' => $document, 'items' => $items ?: [['name' => '', 'quantity' => 1, 'unit' => 'NOS']],
            'canUseZoho' => $canUseZoho,
            'destination' => $destination, 'creationKey' => (string) Str::uuid(), 'locations' => SiteLocation::visibleLocations(),
            'outwardShipments' => OutwardShipment::query()->whereIn('status', ['documents_pending', 'ready', 'dispatched'])->latest('id')->limit(100)->get(['id', 'number', 'to_name']),
            'documents' => ZohoSalesDocument::select(['id', 'type', 'number', 'customer_name', 'document_date'])->where('organization_id', $org)->whereIn('type', ['salesorder', 'invoice'])
                ->when(! $canUseZoho, fn ($q) => $q->whereRaw('1 = 0'))
                ->when($filter['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
                ->when(trim($filter['q'] ?? ''), fn ($q, $term) => $q->where(fn ($q) => $q->where('number', 'like', $term.'%')->orWhere('customer_name', 'like', $term.'%')))
                ->latest('document_date')->latest('id')->paginate(10)->withQueryString(),
            'packages' => $sourceOrder ? ZohoSalesDocument::where('organization_id', $org)->where('type', 'package')->where('salesorder_id', $sourceOrder)->limit(20)->pluck('number') : collect(),
        ]);
    }

    public function refreshDocument(Request $request, ZohoSalesDocument $document, ZohoReadClient $zoho, ZohoApiGate $gate)
    {
        abort_unless($this->service->canMarkRtd($request->user()) && $this->service->canUseZoho($request->user()), 403);
        abort_unless($document->organization_id === (string) config('services.zoho_items_pull.organization_id') && in_array($document->type, ['invoice', 'salesorder']), 403);
        $path = $document->type === 'invoice' ? '/invoices/' : '/salesorders/';
        $body = $zoho->get($path.rawurlencode($document->zoho_id), [], $gate);
        $details = $body[$document->type] ?? null;
        abort_unless(($body['code'] ?? 0) == 0 && is_array($details) && (string) ($details[$document->type.'_id'] ?? '') === $document->zoho_id, 502, 'Zoho did not return the requested document.');
        $document->update(['payload' => $details, 'synced_at' => now()]);

        return redirect()->route('logistics.create', ['document' => $document->id])->with('status', 'Zoho line items and delivery address refreshed.');
    }

    public function store(Request $request)
    {
        $shipment = $this->service->markRtd($request->all(), $request->user());

        return redirect()->route('logistics.show', $shipment)->with('status', 'RTD received. Logistics can now obtain quotes and assign transport.');
    }

    public function show(Request $request, Shipment $shipment)
    {
        $this->service->assertRead($request->user(), $shipment);
        $shipment->load(['transporter', 'vehicleType', 'document', 'quotes' => fn ($q) => $q->with('transporter')->orderBy('amount')->orderBy('vendor_rank')->orderBy('id'), 'charges', 'revisions' => fn ($q) => $q->latest('id'), 'attachments' => fn ($q) => $q->latest('id')]);

        return view('logistics.show', [
            'shipment' => $shipment,
            'events' => $shipment->events()->with('actor:id,name')->latest('id')->paginate(15)->withQueryString(),
            'transporters' => Transporter::active()->whereIn('approval_status', ['approved', 'active'])->orderBy('name')->limit(500)->get(['id', 'name', 'code']),
            'vehicleTypes' => VehicleType::where('active', true)->orderBy('name')->limit(100)->get(),
            'canManage' => $this->service->canManage($request->user()), 'canDeliver' => $this->service->canDeliver($request->user()),
        ]);
    }

    public function action(Request $request, Shipment $shipment, string $action)
    {
        $messages = ['quote' => 'Quotation saved.', 'plan' => 'Transport plan assigned.', 'transition' => 'Shipment status updated.', 'edd' => 'EDD amended with its reason recorded.', 'charge' => 'Additional charge recorded.', 'completion' => 'Delivery details saved.', 'attachment' => 'Private document uploaded.'];
        abort_unless(isset($messages[$action]), 404);
        match ($action) {
            'quote' => $this->service->quote($shipment, $request->all(), $request->user()),
            'plan' => $this->service->plan($shipment, $request->all(), $request->user()),
            'transition' => $this->service->transition($shipment, $request->all(), $request->user()),
            'edd' => $this->service->amendEdd($shipment, $request->all(), $request->user()),
            'charge' => $this->service->charge($shipment, $request->all(), $request->user()),
            'completion' => $this->service->completion($shipment, $request->all(), $request->user()),
            'attachment' => $this->service->attach($shipment, $request->all(), $request->user()),
        };

        return redirect()->route('logistics.show', $shipment)->with('status', $messages[$action]);
    }

    public function voidCharge(Request $request, Shipment $shipment, int $charge)
    {
        $this->service->voidCharge($shipment, $charge, $request->all(), $request->user());

        return redirect()->route('logistics.show', $shipment)->with('status', 'Charge voided; its original record remains in the audit trail.');
    }

    public function vehicleType(Request $request)
    {
        abort_unless($this->service->canManage($request->user()), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:logistics_vehicle_types,name']]);
        VehicleType::create($data);

        return back()->with('status', 'Vehicle type added.');
    }

    public function download(Request $request, Attachment $attachment)
    {
        $this->service->assertRead($request->user(), $attachment->shipment);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function export(Request $request)
    {
        $query = $this->filtered($request)->select(['id', 'number', 'source_kind', 'reference_number', 'channel', 'provider_name', 'destination', 'status', 'transporter_id', 'currency', 'base_freight', 'revenue', 'expected_delivery_at', 'dispatched_at', 'delivered_at'])->with('transporter:id,name')->withSum(['charges as extras' => fn ($q) => $q->whereNull('voided_at')], 'amount');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Shipment', 'Source', 'Reference', 'Channel', 'Provider', 'Destination', 'Status', 'Transporter', 'Currency', 'Freight', 'Extras', 'Total cost', 'Revenue', 'EDD', 'Dispatched', 'Delivered'], escape: '');
            $query->reorder()->chunkById(500, function ($rows) use ($out) {
                foreach ($rows as $s) {
                    $values = [$s->number, $s->source_kind, $s->reference_number, $s->channel, $s->provider_name, $s->destination, Shipment::STATUSES[$s->status], $s->transporter?->name, $s->currency, $s->base_freight, $s->extras ?? 0, number_format(((int) round((float) $s->base_freight * 100) + (int) round((float) ($s->extras ?? 0) * 100)) / 100, 2, '.', ''), $s->revenue, $s->expected_delivery_at?->toDateTimeString(), $s->dispatched_at?->toDateTimeString(), $s->delivered_at?->toDateTimeString()];
                    fputcsv($out, array_map(fn ($v) => preg_match('/^[\s]*[=+@-]/u', (string) $v) ? "'".$v : $v, $values), escape: '');
                }
            });
            fclose($out);
        }, 'logistics-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
