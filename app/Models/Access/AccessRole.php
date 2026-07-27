<?php

namespace App\Models\Access;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccessRole extends Model
{
    public const LEVEL_SUPER_ADMIN = 1;

    public const LEVEL_HEAD = 2;

    public const LEVEL_MANAGER = 3;

    public const LEVEL_EXECUTIVE = 4;

    protected $fillable = ['code', 'name', 'label', 'level', 'hierarchy_level', 'vertical_id', 'parent_role_id', 'role_kind', 'is_system', 'status', 'version', 'description', 'created_by'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'level' => 'integer', 'hierarchy_level' => 'integer'];
    }

    public function vertical(): BelongsTo
    {
        return $this->belongsTo(AccessVertical::class, 'vertical_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_role_id');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(AccessPermission::class, 'access_role_permissions', 'role_id', 'permission_id')
            ->withPivot(['allowed', 'effect', 'delegable', 'scope_type', 'max_scope_type', 'scope_json', 'scope_constraints_json', 'field_mode', 'locked'])
            ->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'access_user_roles', 'role_id', 'user_id')
            ->withPivot(['assigned_by', 'starts_at', 'expires_at', 'status'])
            ->withTimestamps();
    }

    public function layouts(): HasMany
    {
        return $this->hasMany(AccessRoleDashboardLayout::class, 'role_id');
    }
}
