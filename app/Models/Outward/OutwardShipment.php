<?php

namespace App\Models\Outward;

use App\Models\Tan90\MasterData\Customer;
use App\Models\Tan90\MasterData\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutwardShipment extends Model
{
    public const PURPOSES = [
        'sales' => 'Sales Order',
        'transfer' => 'Transfer Order / Internal Movement',
        'job_work' => 'Job Work',
    ];

    public const DOCUMENT_TYPES = [
        'tax_invoice' => 'Tax Invoice',
        'delivery_challan' => 'Delivery Challan',
    ];

    protected $fillable = [
        'number', 'purpose', 'from_location_id', 'from_name', 'from_gstin', 'from_state',
        'customer_id', 'to_location_id', 'to_name', 'to_gstin', 'to_state', 'to_address', 'sales_order_number', 'total_value',
        'document_type', 'is_b2b', 'requires_irn', 'requires_ewb', 'decision_steps',
        'invoice_number', 'invoice_date', 'invoice_file', 'challan_number',
        'irn', 'irn_ack_number', 'irn_ack_date', 'irn_file',
        'ewb_number', 'ewb_date', 'ewb_valid_until', 'ewb_file',
        'vehicle_number', 'transporter', 'notes', 'status', 'dispatched_at', 'dispatched_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'total_value' => 'decimal:2', 'is_b2b' => 'boolean', 'requires_irn' => 'boolean', 'requires_ewb' => 'boolean',
            'decision_steps' => 'array', 'invoice_date' => 'date', 'irn_ack_date' => 'date', 'ewb_date' => 'date',
            'ewb_valid_until' => 'date', 'dispatched_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OutwardShipmentLine::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function purposeLabel(): string
    {
        return self::PURPOSES[$this->purpose] ?? $this->purpose;
    }

    public function documentLabel(): string
    {
        return self::DOCUMENT_TYPES[$this->document_type] ?? $this->document_type;
    }

    /**
     * Documents still missing before this shipment may leave, as plain labels.
     *
     * @return array<int, string>
     */
    public function missingDocuments(): array
    {
        $missing = [];
        if ($this->document_type === 'tax_invoice' && (blank($this->invoice_number) || ! $this->invoice_date || blank($this->invoice_file))) {
            $missing[] = 'Tax Invoice (number, date and file)';
        }
        if ($this->requires_irn && (blank($this->irn) || blank($this->irn_ack_number))) {
            $missing[] = 'e-Invoice IRN and Ack No.';
        }
        if ($this->requires_ewb && (blank($this->ewb_number) || ! $this->ewb_valid_until || blank($this->ewb_file))) {
            $missing[] = 'E-Way Bill (number, valid-until date and file)';
        }

        return $missing;
    }
}
