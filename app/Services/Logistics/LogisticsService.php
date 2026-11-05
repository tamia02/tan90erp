<?php

namespace App\Services\Logistics;

use App\Models\Logistics\Shipment;
use App\Models\Logistics\VehicleType;
use App\Models\Outward\OutwardShipment;
use App\Models\Tan90\MasterData\Transporter;
use App\Models\User;
use App\Models\ZohoSalesDocument;
use App\Services\Access\AccessControlService;
use App\Support\SiteLocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LogisticsService
{
    public function __construct(private AccessControlService $access) {}

    public function canRead(User $actor): bool
    {
        return $this->access->can($actor, 'flow.order.view') || $this->canManage($actor) || $this->canDeliver($actor) || $this->canMarkRtd($actor);
    }

    public function canManage(User $actor): bool
    {
        return $this->access->can($actor, 'flow.dispatch.manage');
    }

    public function canDeliver(User $actor): bool
    {
        return $this->access->can($actor, 'flow.delivery.manage');
    }

    public function canMarkRtd(User $actor): bool
    {
        return $this->access->can($actor, 'forge.workorder.release') || $this->access->can($actor, 'flow.order.create');
    }

    public function canUseZoho(User $actor): bool
    {
        return $this->access->can($actor, 'flow.order.view');
    }

    public function assertRead(User $actor, Shipment $shipment): void
    {
        abort_unless($this->canRead($actor), 403);
        SiteLocation::assertCanAccessSiteRecord($actor, $shipment->site_id === null ? null : (int) $shipment->site_id);
    }

    public function visible(User $actor)
    {
        $site = SiteLocation::forUser($actor);

        return Shipment::query()->when($site !== null, fn ($q) => $q->where('site_id', $site));
    }

    public function markRtd(array $input, User $actor): Shipment
    {
        abort_unless($this->canMarkRtd($actor), 403);
        $data = Validator::make($input, [
            'creation_key' => ['required', 'uuid'], 'site_id' => ['required', 'exists:tan90_locations,id'],
            'channel' => ['required', Rule::in(['d2c', 'inland'])], 'source_kind' => ['required', Rule::in(['Sales', 'DC', 'TO', 'PO'])],
            'reference_number' => ['required', 'string', 'max:255'], 'provider_name' => ['required', 'string', 'max:255'],
            'source_location' => ['required', 'string', 'max:255'], 'vertical' => ['nullable', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'max:255'], 'destination' => ['required', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'], 'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999'], 'items.*.unit' => ['required', 'string', 'max:30'],
            'weight_kg' => ['required', 'numeric', 'min:0', 'max:999999999'], 'priority' => ['required', Rule::in(['normal', 'high', 'urgent'])],
            'package_reference' => ['nullable', 'string', 'max:500'], 'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'revenue' => ['nullable', 'numeric', 'min:0', 'max:99999999999', 'decimal:0,2'],
            'rtd_at' => ['required', 'date', 'before_or_equal:now'], 'rtd_confirmed' => ['accepted'],
            'zoho_sales_document_id' => ['nullable', 'integer', 'exists:zoho_sales_documents,id'],
            'outward_shipment_id' => ['nullable', 'integer', 'exists:outward_shipments,id'], 'notes' => ['nullable', 'string', 'max:3000'],
        ])->validate();
        SiteLocation::assertCanAccessSiteRecord($actor, (int) $data['site_id']);
        if ($data['channel'] === 'd2c' && $data['source_kind'] !== 'Sales') {
            throw ValidationException::withMessages(['source_kind' => 'D2C shipments originate from Sales.']);
        }
        unset($data['rtd_confirmed']);

        return DB::transaction(function () use ($data, $actor) {
            $existing = Shipment::where('creation_key', $data['creation_key'])->first();
            if ($existing) {
                $this->assertRead($actor, $existing);
                abort_unless((int) $existing->created_by === $actor->id, 403);

                return $existing;
            }
            if (! empty($data['zoho_sales_document_id'])) {
                abort_unless($this->canUseZoho($actor), 403);
                $doc = ZohoSalesDocument::whereKey($data['zoho_sales_document_id'])->lockForUpdate()->firstOrFail();
                abort_unless($doc->organization_id === (string) config('services.zoho_items_pull.organization_id'), 403);
                if ($doc->type !== ($data['channel'] === 'd2c' ? 'salesorder' : 'invoice')) {
                    throw ValidationException::withMessages(['zoho_sales_document_id' => 'Load a sales order for D2C or an RTD invoice for Inland.']);
                }
                if (Shipment::where('zoho_sales_document_id', $doc->id)->exists()) {
                    throw ValidationException::withMessages(['zoho_sales_document_id' => 'This Zoho document already has a logistics shipment.']);
                }
                $currency = $doc->payload['currency_code'] ?? null;
                if ($currency && strtoupper($currency) !== $data['currency']) {
                    throw ValidationException::withMessages(['currency' => 'Use the linked document currency for this shipment.']);
                }
                $data['reference_number'] = $doc->number;
            }
            if (! empty($data['outward_shipment_id'])) {
                $outward = OutwardShipment::withoutGlobalScopes()->findOrFail($data['outward_shipment_id']);
                if ((int) $outward->from_location_id !== (int) $data['site_id']) {
                    throw ValidationException::withMessages(['outward_shipment_id' => 'The linked outward shipment must leave from the same site.']);
                }
            }
            $shipment = Shipment::create($data + ['number' => 'LG-'.Str::ulid(), 'created_by' => $actor->id, 'status' => 'rtd']);
            $this->event($shipment, $actor, 'rtd', null, 'rtd', 'Manufacturing RTD handoff recorded.');

            return $shipment;
        }, 3);
    }

    private function change(Shipment $shipment, array $input, User $actor, callable $action, bool $delivery = false, int $attempts = 3): Shipment
    {
        abort_unless($delivery ? $this->canDeliver($actor) : $this->canManage($actor), 403);
        $this->assertRead($actor, $shipment);
        Validator::make($input, ['version' => ['required', 'integer', 'min:1']])->validate();

        return DB::transaction(function () use ($shipment, $input, $actor, $action) {
            $locked = Shipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->version !== (int) $input['version']) {
                throw ValidationException::withMessages(['version' => 'This shipment changed. Reload before submitting again.']);
            }
            $action($locked, $actor);
            $locked->version++;
            $locked->save();

            return $locked;
        }, $attempts);
    }

    private function allowed(Shipment $shipment, array $states): void
    {
        if (! in_array($shipment->status, $states, true)) {
            throw ValidationException::withMessages(['status' => 'This action is not available at the current shipment stage.']);
        }
    }

    private function transporter(int $id): Transporter
    {
        $transporter = Transporter::whereKey($id)->where('status', 'active')->whereIn('approval_status', ['approved', 'active'])->first();
        if (! $transporter) {
            throw ValidationException::withMessages(['transporter_id' => 'Choose an active, approved transporter from Master Data.']);
        }

        return $transporter;
    }

    public function quote(Shipment $shipment, array $input, User $actor): Shipment
    {
        $data = Validator::make($input, ['transporter_id' => ['required', 'integer'], 'amount' => ['required', 'numeric', 'min:0', 'max:99999999999', 'decimal:0,2'], 'vendor_rank' => ['required', 'integer', 'min:1', 'max:9999'], 'notes' => ['nullable', 'string', 'max:2000']])->validate();

        return $this->change($shipment, $input, $actor, function ($s, $actor) use ($data) {
            $this->allowed($s, ['rtd']);
            $this->transporter((int) $data['transporter_id']);
            $s->quotes()->updateOrCreate(['transporter_id' => $data['transporter_id']], $data + ['created_by' => $actor->id]);
            $this->event($s, $actor, 'quote', $s->status, $s->status, 'Transporter quotation recorded.', $data);
        });
    }

    public function plan(Shipment $shipment, array $input, User $actor): Shipment
    {
        $data = Validator::make($input, [
            'transport_mode' => ['required', Rule::in(Shipment::MODES)], 'transporter_id' => ['required', 'integer'],
            'vehicle_type_id' => ['nullable', 'integer'], 'vehicle_number' => ['nullable', 'string', 'max:40'],
            'driver_name' => ['nullable', 'string', 'max:255'], 'driver_phone' => ['nullable', 'string', 'max:30'],
            'base_freight' => ['nullable', 'numeric', 'min:0', 'max:99999999999', 'decimal:0,2'],
            'selection_reason' => ['nullable', 'string', 'max:2000'], 'expected_delivery_at' => ['required', 'date'],
        ])->validate();

        return $this->change($shipment, $input, $actor, function ($s, $actor) use ($data) {
            $this->allowed($s, ['rtd', 'assigned']);
            $this->transporter((int) $data['transporter_id']);
            if ($s->channel === 'd2c' && ! in_array($data['transport_mode'], ['Express', 'Premium'], true)) {
                throw ValidationException::withMessages(['transport_mode' => 'D2C supports Express or Premium.']);
            }
            if ($s->channel === 'inland' && empty($data['vehicle_type_id'])) {
                throw ValidationException::withMessages(['vehicle_type_id' => 'Choose the vehicle type for an inland shipment.']);
            }
            if (! empty($data['vehicle_type_id']) && ! VehicleType::whereKey($data['vehicle_type_id'])->where('active', true)->exists()) {
                throw ValidationException::withMessages(['vehicle_type_id' => 'Choose an active vehicle type.']);
            }
            if ($s->channel === 'inland' && in_array($data['transport_mode'], ['FTL', 'PTL', 'Porter', 'Non-Regular'], true)) {
                foreach (['vehicle_number', 'driver_name', 'driver_phone'] as $field) {
                    if (blank($data[$field] ?? null)) {
                        throw ValidationException::withMessages([$field => 'Road shipments require vehicle, driver name and driver mobile.']);
                    }
                }
            }
            if (Carbon::parse($data['expected_delivery_at'])->lt($s->rtd_at)) {
                throw ValidationException::withMessages(['expected_delivery_at' => 'EDD cannot precede the RTD handoff.']);
            }
            $quotes = $s->quotes()->orderBy('amount')->orderBy('vendor_rank')->orderBy('id')->get();
            $chosen = $quotes->firstWhere('transporter_id', (int) $data['transporter_id']);
            if (($s->channel === 'inland' || $quotes->isNotEmpty()) && ! $chosen) {
                throw ValidationException::withMessages(['transporter_id' => 'Record a quote for the selected inland transporter first.']);
            }
            if ($chosen && $quotes->first()->id !== $chosen->id && mb_strlen(trim($data['selection_reason'] ?? '')) < 5) {
                throw ValidationException::withMessages(['selection_reason' => 'Explain why a higher-cost/lower-ranked quote was selected.']);
            }
            if (! $chosen && ! isset($data['base_freight'])) {
                throw ValidationException::withMessages(['base_freight' => 'Enter the agreed freight or record a transporter quote.']);
            }
            $data['base_freight'] = $chosen?->amount ?? $data['base_freight'];
            $from = $s->status;
            $s->fill($data);
            $s->status = 'assigned';
            $this->event($s, $actor, 'assigned', $from, 'assigned', $data['selection_reason'] ?? 'Transport plan assigned.', $data);
        });
    }

    public function transition(Shipment $shipment, array $input, User $actor): Shipment
    {
        $data = Validator::make($input, ['to_status' => ['required', Rule::in(['in_transit', 'at_location', 'delivered'])], 'actual_at' => ['required', 'date', 'before_or_equal:now'], 'notes' => ['nullable', 'string', 'max:2000']])->validate();

        return $this->change($shipment, $input, $actor, function ($s, $actor) use ($data) {
            $previous = ['in_transit' => 'assigned', 'at_location' => 'in_transit', 'delivered' => 'at_location'];
            $this->allowed($s, [$previous[$data['to_status']]]);
            $prior = match ($data['to_status']) {
                'in_transit' => $s->rtd_at, 'at_location' => $s->dispatched_at, default => $s->arrived_at
            };
            if (Carbon::parse($data['actual_at'])->lt($prior)) {
                throw ValidationException::withMessages(['actual_at' => 'Actual time cannot precede the previous handoff.']);
            }
            if ($data['to_status'] === 'in_transit' && $s->outward_shipment_id) {
                $outward = OutwardShipment::withoutGlobalScopes()->findOrFail($s->outward_shipment_id);
                if ($outward->status !== 'dispatched') {
                    throw ValidationException::withMessages(['status' => 'The linked outward gate shipment must be dispatched before recording departure here.']);
                }
            }
            $field = ['in_transit' => 'dispatched_at', 'at_location' => 'arrived_at', 'delivered' => 'delivered_at'][$data['to_status']];
            $from = $s->status;
            $s->$field = $data['actual_at'];
            $s->status = $data['to_status'];
            $this->event($s, $actor, 'status', $from, $s->status, $data['notes'] ?? null, ['actual_at' => $data['actual_at']]);
        }, $data['to_status'] !== 'in_transit');
    }

    public function amendEdd(Shipment $shipment, array $input, User $actor): Shipment
    {
        $data = Validator::make($input, ['expected_delivery_at' => ['required', 'date'], 'reason' => ['required', 'string', 'min:5', 'max:2000']])->validate();

        return $this->change($shipment, $input, $actor, function ($s, $actor) use ($data) {
            $this->allowed($s, ['in_transit']);
            $new = Carbon::parse($data['expected_delivery_at']);
            if ($new->lt($s->dispatched_at) || $new->equalTo($s->expected_delivery_at)) {
                throw ValidationException::withMessages(['expected_delivery_at' => 'Choose a changed EDD that is not before dispatch.']);
            }
            $s->revisions()->create(['old_edd' => $s->expected_delivery_at, 'new_edd' => $new, 'reason' => $data['reason'], 'actor_id' => $actor->id]);
            $this->event($s, $actor, 'edd_amended', $s->status, $s->status, $data['reason'], ['old' => $s->expected_delivery_at->toIso8601String(), 'new' => $new->toIso8601String()]);
            $s->expected_delivery_at = $new;
        });
    }

    public function charge(Shipment $shipment, array $input, User $actor): Shipment
    {
        $data = Validator::make($input, ['type' => ['required', Rule::in(['loading', 'unloading', 'halting', 'other'])], 'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999999', 'decimal:0,2'], 'remarks' => ['required', 'string', 'min:3', 'max:2000']])->validate();

        return $this->change($shipment, $input, $actor, function ($s, $actor) use ($data) {
            $this->allowed($s, ['assigned', 'in_transit', 'at_location', 'delivered']);
            $s->charges()->create($data + ['created_by' => $actor->id]);
            $this->event($s, $actor, 'charge', $s->status, $s->status, $data['remarks'], $data);
        });
    }

    public function voidCharge(Shipment $shipment, int $id, array $input, User $actor): Shipment
    {
        $data = Validator::make($input, ['reason' => ['required', 'string', 'min:5', 'max:2000']])->validate();

        return $this->change($shipment, $input, $actor, function ($s, $actor) use ($id, $data) {
            $charge = $s->charges()->whereKey($id)->whereNull('voided_at')->firstOrFail();
            $charge->update(['voided_at' => now(), 'void_reason' => $data['reason']]);
            $this->event($s, $actor, 'charge_voided', $s->status, $s->status, $data['reason'], ['charge_id' => $id]);
        });
    }

    public function completion(Shipment $shipment, array $input, User $actor): Shipment
    {
        $data = Validator::make($input, ['google_maps_url' => ['nullable', 'url:https', 'max:1000'], 'poc_name' => ['nullable', 'string', 'max:255'], 'poc_phone' => ['nullable', 'string', 'max:30'], 'revenue' => ['nullable', 'numeric', 'min:0', 'max:99999999999', 'decimal:0,2'], 'notes' => ['nullable', 'string', 'max:3000']])->validate();
        if (! empty($data['google_maps_url']) && ! in_array(strtolower(parse_url($data['google_maps_url'], PHP_URL_HOST) ?? ''), ['google.com', 'www.google.com', 'maps.google.com', 'maps.app.goo.gl', 'maps.google.co.in', 'www.google.co.in'], true)) {
            throw ValidationException::withMessages(['google_maps_url' => 'Use a Google Maps HTTPS link.']);
        }

        return $this->change($shipment, $input, $actor, function ($s, $actor) use ($data) {
            $this->allowed($s, ['delivered']);
            $s->fill($data);
            $this->event($s, $actor, 'completion', $s->status, $s->status, 'Delivery completion details updated.', $data);
        }, true);
    }

    public function attach(Shipment $shipment, array $input, User $actor): Shipment
    {
        $data = Validator::make($input, ['type' => ['required', Rule::in(['pod', 'eway', 'other'])], 'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']])->validate();
        $path = null;
        try {
            return $this->change($shipment, $input, $actor, function ($s, $actor) use ($data, &$path) {
                if ($data['type'] === 'pod') {
                    $this->allowed($s, ['delivered']);
                }
                $path = $data['file']->store('logistics/'.$s->id, 'local');
                if (! $path) {
                    throw ValidationException::withMessages(['file' => 'Could not store this document. Please try again.']);
                }
                $s->attachments()->create(['type' => $data['type'], 'path' => $path, 'original_name' => mb_substr(basename($data['file']->getClientOriginalName()), 0, 240), 'uploaded_by' => $actor->id]);
                $this->event($s, $actor, 'attachment', $s->status, $s->status, strtoupper($data['type']).' uploaded.');
            }, $data['type'] === 'pod', 1);
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }

    private function event(Shipment $s, User $actor, string $type, ?string $from, ?string $to, ?string $reason = null, array $details = []): void
    {
        $s->events()->create(['type' => $type, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'details' => $details, 'actor_id' => $actor->id]);
    }
}
