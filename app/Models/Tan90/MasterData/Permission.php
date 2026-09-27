<?php

namespace App\Models\Tan90\MasterData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $table = 'tan90_permissions';

    protected $fillable = ['key', 'label'];

    /**
     * The fixed capability catalog. Matches the demo's ROLE_PERMISSIONS keys
     * (view/create/edit/delete/approve/export/settings) 1:1 so the Permission
     * Matrix screen behaves identically to the frontend demo.
     */
    public const CATALOG = [
        'view' => 'View',
        'create' => 'Create',
        'edit' => 'Edit',
        'delete' => 'Delete / Archive',
        'approve' => 'Approve',
        'export' => 'Export',
        'settings' => 'Settings',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'tan90_role_permission', 'tan90_permission_id', 'tan90_role_id')
            ->withPivot('allowed')
            ->withTimestamps();
    }
}
