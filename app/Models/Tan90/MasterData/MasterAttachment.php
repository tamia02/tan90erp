<?php

namespace App\Models\Tan90\MasterData;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MasterAttachment extends Model
{
    protected $table = 'tan90_master_attachments';

    protected $fillable = [
        'entity_type', 'entity_id', 'tan90_document_rule_id', 'document_label',
        'original_filename', 'storage_path', 'mime_type', 'size', 'uploaded_by',
    ];

    public function documentRule(): BelongsTo
    {
        return $this->belongsTo(DocumentRule::class, 'tan90_document_rule_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
