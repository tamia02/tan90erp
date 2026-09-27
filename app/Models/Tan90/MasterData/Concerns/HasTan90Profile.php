<?php

namespace App\Models\Tan90\MasterData\Concerns;

use App\Models\Tan90\MasterData\UserProfile;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Add `use HasTan90Profile;` to your app's User model to link it to this
 * module's role/permission/plant-scope profile. Intentionally not applied by
 * editing App\Models\User directly - see docs/INSTALL.md.
 */
trait HasTan90Profile
{
    public function tan90Profile(): HasOne
    {
        return $this->hasOne(UserProfile::class, 'user_id');
    }
}
