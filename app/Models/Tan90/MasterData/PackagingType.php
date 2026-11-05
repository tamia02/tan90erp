<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Model;

class PackagingType extends Model
{
    use IsMasterRecord;

    protected $table = 'tan90_packaging_types';

    protected $fillable = [
        'code', 'name', 'requires_box_size', 'status', 'approval_status',
    ];

    protected function casts(): array
    {
        return ['requires_box_size' => 'boolean'];
    }
}
