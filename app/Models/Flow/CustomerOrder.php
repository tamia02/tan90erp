<?php

namespace App\Models\Flow;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class CustomerOrder extends Model
{
    public const STATUSES = [
        'draft', 'validated', 'released', 'atp_confirmed', 'atp_partial', 'allocated', 'waved',
        'picking', 'picked', 'packing', 'packed', 'dispatch_planned', 'loading', 'in_transit',
        'pod_received', 'delivered', 'closed', 'on_hold', 'cancelled',
    ];

    protected $table = 'flow_customer_orders';

    protected $fillable = [
        'order_number', 'customer_name', 'destination', 'temperature_requirement',
        'min_shelf_life_days', 'requested_date', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return ['requested_date' => 'date'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class, 'customer_order_id');
    }

    public function handlingUnits(): HasMany
    {
        return $this->hasMany(HandlingUnit::class, 'customer_order_id');
    }

    public function allocations(): HasManyThrough
    {
        return $this->hasManyThrough(Allocation::class, OrderLine::class, 'customer_order_id', 'order_line_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(ReturnRequest::class, 'customer_order_id');
    }
}
