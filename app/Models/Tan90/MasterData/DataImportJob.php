<?php

namespace App\Models\Tan90\MasterData;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataImportJob extends Model
{
    protected $table = 'tan90_data_import_jobs';

    protected $fillable = [
        'entity_type', 'original_filename', 'storage_path', 'file_hash', 'column_map',
        'total_rows', 'valid_rows', 'invalid_rows', 'duplicate_rows', 'result',
        'started_by', 'completed_at',
    ];

    protected $casts = [
        'column_map' => 'array',
        'completed_at' => 'datetime',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(DataImportRow::class, 'tan90_data_import_job_id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }
}
