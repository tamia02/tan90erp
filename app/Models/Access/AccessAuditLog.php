<?php

namespace App\Models\Access;

use Illuminate\Database\Eloquent\Model;

class AccessAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_id',
        'actor_user_id',
        'action',
        'target_type',
        'target_id',
        'before_json',
        'after_json',
        'hierarchy_context_json',
        'reason',
        'request_id',
        'ip',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before_json' => 'array',
            'after_json' => 'array',
            'hierarchy_context_json' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
