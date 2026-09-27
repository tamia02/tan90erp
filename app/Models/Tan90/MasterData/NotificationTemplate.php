<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsConfigRecord;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    use IsConfigRecord;

    protected $table = 'tan90_notification_templates';

    protected $fillable = ['code', 'name', 'channel', 'subject', 'recipient', 'trigger_event', 'language', 'status'];

    /** Fills {{placeholder}} tokens in the subject with $context values. */
    public function renderSubject(array $context): string
    {
        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function ($matches) use ($context) {
            return (string) ($context[$matches[1]] ?? $matches[0]);
        }, $this->subject);
    }
}
