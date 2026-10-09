<?php

namespace App\Models\Inventory;

use App\Models\Access\AccessPermission;
use App\Models\Access\AccessUserPermissionOverride;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Database\Eloquent\Model;

class ItemCustomField extends Model
{
    public const TYPES = ['text' => 'Text', 'number' => 'Number', 'dropdown' => 'Drop-down'];

    public const PERMISSION = 'inventory.custom_fields.manage';

    protected $fillable = ['field_key', 'label', 'input_type', 'options', 'required', 'active', 'sort_order'];

    protected function casts(): array
    {
        return ['options' => 'array', 'required' => 'boolean', 'active' => 'boolean'];
    }

    public static function activeFields()
    {
        return static::where('active', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    // Super Admin always; anyone else only when Super Admin has given them this
    // one permission (Access Control > People > Extra access, or via a role).
    // Older "legacy" accounts skip the permission engine, so their direct
    // extra-access grant is read here too.
    public static function canManage(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->isAdmin() || app(AccessControlService::class)->can($user, self::PERMISSION)) {
            return true;
        }
        $permissionId = AccessPermission::where('key', self::PERMISSION)->value('id');

        return $permissionId !== null && AccessUserPermissionOverride::where('user_id', $user->id)
            ->where('permission_id', $permissionId)->where('allowed', true)->where('status', 'active')
            ->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }
}
