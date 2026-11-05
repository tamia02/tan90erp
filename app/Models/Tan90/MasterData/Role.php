<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use IsMasterRecord;

    private const BRC_DASHBOARD_ROLE_CODES = [
        'ROLE-RND',
        'ROLE-FORMULATION',
        'ROLE-COSTING',
        'ROLE-PRODUCTION-ENG',
        'ROLE-QA-APPROVER',
    ];

    protected $table = 'tan90_roles';

    protected $fillable = ['code', 'name', 'type', 'data_scope', 'status'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'tan90_role_permission', 'tan90_role_id', 'tan90_permission_id')
            ->withPivot('allowed')
            ->withTimestamps();
    }

    public function userProfiles(): HasMany
    {
        return $this->hasMany(UserProfile::class, 'tan90_role_id');
    }

    public function can(string $permissionKey): bool
    {
        return $this->permissions()
            ->where('tan90_permissions.key', $permissionKey)
            ->wherePivot('allowed', true)
            ->exists();
    }

    public function homeRouteName(): string
    {
        return in_array($this->code, self::BRC_DASHBOARD_ROLE_CODES, true)
            ? 'tan90.brc.dashboard'
            : 'tan90.master-data.dashboard';
    }
}
