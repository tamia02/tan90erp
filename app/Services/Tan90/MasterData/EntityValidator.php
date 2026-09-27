<?php

namespace App\Services\Tan90\MasterData;

use Illuminate\Validation\Rule;

/**
 * Builds Laravel validation rules from an entity's `fields` config, so the
 * same rule set backs both the web FormRequest flow and the CSV import
 * row-by-row validation (Codex prompt: "Validate each row").
 */
class EntityValidator
{
    public function rules(array $entity, ?int $ignoreId = null): array
    {
        $rules = [];

        foreach ($entity['fields'] as $field) {
            $name = $field['name'];
            $set = [$field['required'] ? 'required' : 'nullable'];

            $set[] = match ($field['type']) {
                'number' => 'numeric',
                'email' => 'email',
                'date' => 'date',
                default => 'string',
            };

            if (isset($field['options'])) {
                $set[] = Rule::in($field['options']);
            }

            if (isset($field['relation'])) {
                $set[] = Rule::exists((new $field['relation']['model'])->getTable(), 'id');
            }

            $rules[$name] = $set;
        }

        // Natural key uniqueness (ignoring soft-deleted rows and the record being updated).
        $codeField = $entity['code'] ?? null;
        if ($codeField && isset($entity['fields'][0]) && $this->hasField($entity, $codeField)) {
            $table = (new $entity['model'])->getTable();
            $unique = Rule::unique($table, $codeField)->whereNull('deleted_at');
            if ($ignoreId) {
                $unique = $unique->ignore($ignoreId);
            }
            $rules[$codeField][] = $unique;
        }

        return $rules;
    }

    private function hasField(array $entity, string $name): bool
    {
        foreach ($entity['fields'] as $field) {
            if ($field['name'] === $name) {
                return true;
            }
        }

        return false;
    }
}
