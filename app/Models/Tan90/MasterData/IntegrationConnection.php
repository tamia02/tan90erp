<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsConfigRecord;
use Illuminate\Database\Eloquent\Model;

class IntegrationConnection extends Model
{
    use IsConfigRecord;

    protected $table = 'tan90_integration_connections';

    protected $fillable = [
        'code', 'name', 'type', 'base_url', 'auth',
        'environment', 'health', 'last_tested_at', 'status',
    ];

    protected $casts = ['last_tested_at' => 'datetime'];
}
