<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsConfigRecord;
use Illuminate\Database\Eloquent\Model;

class DocumentRule extends Model
{
    use IsConfigRecord;

    protected $table = 'tan90_document_rules';

    protected $fillable = [
        'code', 'name', 'entity', 'mandatory', 'optional',
        'max_size', 'allowed_types', 'retention', 'status',
    ];

    /** @return string[] trimmed labels parsed from the comma-separated `mandatory` field */
    public function mandatoryLabels(): array
    {
        return $this->splitLabels($this->mandatory);
    }

    /** @return string[] trimmed labels parsed from the comma-separated `optional` field */
    public function optionalLabels(): array
    {
        return $this->splitLabels($this->optional);
    }

    private function splitLabels(?string $value): array
    {
        if (! $value) {
            return [];
        }

        return collect(explode(',', $value))
            ->map(fn ($label) => trim($label))
            ->filter()
            ->values()
            ->all();
    }
}
