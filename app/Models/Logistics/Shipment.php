<?php

namespace App\Models\Logistics;

use App\Models\Tan90\MasterData\Transporter;
use App\Models\ZohoSalesDocument;
use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    public const STATUSES = ['rtd' => 'Ready to Dispatch', 'assigned' => 'Assigned to Logistics', 'in_transit' => 'In-Transit', 'at_location' => 'At the Location', 'delivered' => 'Delivered'];

    public const MODES = ['FTL', 'PTL', 'Porter', 'Express', 'Premium', 'Non-Regular', 'Air Freight'];

    public const CHANNELS = ['d2c' => 'D2C', 'inland' => 'Inland'];

    protected $table = 'logistics_shipments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array', 'weight_kg' => 'decimal:3', 'base_freight' => 'decimal:2', 'revenue' => 'decimal:2', 'rtd_at' => 'datetime', 'expected_delivery_at' => 'datetime', 'dispatched_at' => 'datetime', 'arrived_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function transporter()
    {
        return $this->belongsTo(Transporter::class)->withTrashed();
    }

    public function vehicleType()
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function document()
    {
        return $this->belongsTo(ZohoSalesDocument::class, 'zoho_sales_document_id');
    }

    public function quotes()
    {
        return $this->hasMany(Quote::class);
    }

    public function charges()
    {
        return $this->hasMany(Charge::class);
    }

    public function events()
    {
        return $this->hasMany(Event::class);
    }

    public function revisions()
    {
        return $this->hasMany(EddRevision::class);
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }

    public function activeChargeCents(): int
    {
        return $this->charges->whereNull('voided_at')->sum(fn ($charge) => (int) round((float) $charge->amount * 100));
    }

    public function totalCost(): string
    {
        return number_format(((int) round((float) $this->base_freight * 100) + $this->activeChargeCents()) / 100, 2, '.', '');
    }
}
