<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    use IsMasterRecord;

    protected $table = 'tan90_user_profiles';

    protected $fillable = [
        'user_id', 'tan90_role_id', 'employee_id', 'department',
        'assigned_plant_id', 'assigned_location_id', 'all_locations',
        'mfa_status', 'status', 'last_login_at',
    ];

    protected $casts = [
        'all_locations' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'tan90_role_id');
    }

    public function assignedPlant(): BelongsTo
    {
        return $this->belongsTo(Plant::class, 'assigned_plant_id');
    }

    public function assignedLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'assigned_location_id');
    }

    /**
     * Plant users are scoped to their assigned plant/location unless
     * all_locations is set (e.g. a plant-tier user with cross-site duties).
     */
    public function isScopedToSingleSite(): bool
    {
        return ! $this->all_locations && ($this->assigned_plant_id || $this->assigned_location_id);
    }
}
