<?php

namespace App\Models\Tan90\MasterData;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit trail. update()/delete() are blocked at the model layer
 * (in addition to no edit/delete routes ever being registered for it) so a
 * record can never be altered once written, per the module's audit-immutability rule.
 */
class MasterAuditLog extends Model
{
    protected $table = 'tan90_master_audit_logs';

    public $timestamps = true;

    protected $fillable = [
        'event', 'module', 'entity_type', 'entity_id', 'record_label',
        'user_id', 'role_label', 'ip_address', 'summary', 'changed_fields', 'occurred_at',
    ];

    protected $casts = [
        'changed_fields' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('Audit log entries are immutable and cannot be updated.');
    }

    public function delete(): bool
    {
        throw new LogicException('Audit log entries are immutable and cannot be deleted.');
    }
}
