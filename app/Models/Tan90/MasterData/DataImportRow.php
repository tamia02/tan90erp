<?php

namespace App\Models\Tan90\MasterData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataImportRow extends Model
{
    protected $table = 'tan90_data_import_rows';

    protected $fillable = [
        'tan90_data_import_job_id', 'row_number', 'source_row_key', 'raw_data',
        'mapped_data', 'errors', 'status', 'matched_entity_id',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'mapped_data' => 'array',
        'errors' => 'array',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(DataImportJob::class, 'tan90_data_import_job_id');
    }
}
