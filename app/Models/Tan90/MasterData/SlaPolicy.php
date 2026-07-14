<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsConfigRecord;
use Illuminate\Database\Eloquent\Model;

class SlaPolicy extends Model
{
    use IsConfigRecord;

    protected $table = 'tan90_sla_policies';

    protected $fillable = [
        'code', 'name', 'applies_to', 'target', 'warning_at',
        'escalate_at', 'escalation_role', 'calendar', 'status',
    ];

    /** Parses a free-text duration like "18 Hours" / "45 minutes" into hours (float). */
    public function warningAtHours(): ?float
    {
        return $this->parseHours($this->warning_at);
    }

    public function escalateAtHours(): ?float
    {
        return $this->parseHours($this->escalate_at) ?? $this->parseHours($this->target);
    }

    private function parseHours(?string $value): ?float
    {
        if (! $value || ! preg_match('/([\d.]+)\s*(hour|hr|minute|min|day)/i', $value, $matches)) {
            return null;
        }

        $number = (float) $matches[1];

        return match (strtolower(substr($matches[2], 0, 3))) {
            'min' => $number / 60,
            'day' => $number * 24,
            default => $number,
        };
    }
}
