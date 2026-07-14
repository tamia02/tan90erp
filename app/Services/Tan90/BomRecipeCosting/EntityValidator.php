<?php

namespace App\Services\Tan90\BomRecipeCosting;

use Illuminate\Validation\Rule;

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

        $codeField = $entity['code'] ?? null;
        if ($codeField && $this->hasField($entity, $codeField)) {
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
