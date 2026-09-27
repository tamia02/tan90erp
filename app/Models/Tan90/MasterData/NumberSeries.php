<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsConfigRecord;
use Illuminate\Database\Eloquent\Model;

class NumberSeries extends Model
{
    use IsConfigRecord;

    protected $table = 'tan90_number_series';

    protected $fillable = ['module', 'prefix', 'pattern', 'next_number', 'reset_policy', 'preview', 'status'];

    /** NumberSeries uses `module` as its natural key/label, not `code`/`name`. */
    public function auditLabel(): string
    {
        return $this->module ?? "NumberSeries #{$this->getKey()}";
    }
}
