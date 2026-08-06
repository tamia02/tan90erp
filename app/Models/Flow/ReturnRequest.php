<?php

namespace App\Models\Flow;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnRequest extends Model
{
    protected $table = 'flow_returns';

    protected $fillable = [
        'rma_number', 'customer_order_id', 'reason', 'qty', 'uom', 'status', 'disposition',
        'inspection_notes', 'claim_raised', 'claim_amount', 'claim_status', 'requested_by', 'inspected_by',
    ];

    protected function casts(): array
    {
        return ['claim_raised' => 'boolean'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class, 'customer_order_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }
}
