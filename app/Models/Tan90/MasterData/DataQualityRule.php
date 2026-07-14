<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsConfigRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataQualityRule extends Model
{
    use IsConfigRecord;

    protected $table = 'tan90_data_quality_rules';

    protected $fillable = ['code', 'entity', 'description', 'default_severity', 'status'];

    public function issues(): HasMany
    {
        return $this->hasMany(DataQualityIssue::class, 'tan90_data_quality_rule_id');
    }
}
