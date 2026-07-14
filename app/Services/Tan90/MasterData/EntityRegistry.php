<?php

namespace App\Services\Tan90\MasterData;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Thin accessor over config('tan90_master_data'). Centralizes entity lookup
 * and relation-option resolution so controllers/views never touch the raw
 * config array directly.
 */
class EntityRegistry
{
    public function all(): array
    {
        return config('tan90_master_data.entities', []);
    }

    public function navGroups(): array
    {
        return config('tan90_master_data.nav_groups', []);
    }

    public function has(string $slug): bool
    {
        return isset(config('tan90_master_data.entities', [])[$slug]);
    }

    public function get(string $slug): array
    {
        $entity = config("tan90_master_data.entities.{$slug}");

        if (! $entity) {
            throw new NotFoundHttpException("Unknown master data entity [{$slug}].");
        }

        $entity['slug'] = $slug;

        return $entity;
    }

    /** @return class-string<Model> */
    public function modelClass(string $slug): string
    {
        return $this->get($slug)['model'];
    }

    public function newModel(string $slug): Model
    {
        $class = $this->modelClass($slug);

        return new $class;
    }

    /**
     * Reverse lookup used by Tan90MasterDataPolicy, which receives a model
     * instance (Laravel's normal policy resolution) but needs the entity's
     * registry config (plant_scope_field, critical_fields, no_approval, ...).
     */
    public function slugForModel(Model $record): ?string
    {
        $class = get_class($record);

        foreach ($this->all() as $slug => $entity) {
            if ($entity['model'] === $class) {
                return $slug;
            }
        }

        return null;
    }

    public function isCriticalField(string $slug, string $field): bool
    {
        return in_array($field, $this->get($slug)['critical_fields'] ?? [], true);
    }

    public function requiresApproval(string $slug): bool
    {
        return empty($this->get($slug)['no_approval']);
    }

    /**
     * Resolve select-field choices: either the field's static 'options' array,
     * or the active records of a related entity ('relation').
     */
    public function fieldOptions(array $field): array
    {
        if (isset($field['options'])) {
            return array_combine($field['options'], $field['options']);
        }

        if (isset($field['relation'])) {
            /** @var class-string<Model> $model */
            $model = $field['relation']['model'];
            $labelField = $field['relation']['label_field'];

            return $model::query()
                ->active()
                ->orderBy($labelField)
                ->get(['id', $labelField])
                ->pluck($labelField, 'id')
                ->all();
        }

        return [];
    }

    /**
     * Dot-path value reader for list/detail columns like "legalEntity.name".
     */
    public function columnValue(Model $record, string $column)
    {
        if (! str_contains($column, '.')) {
            return $record->getAttribute($column);
        }

        [$relation, $attribute] = explode('.', $column, 2);
        $related = $record->relationLoaded($relation) || method_exists($record, $relation)
            ? $record->{$relation}
            : null;

        return $related?->getAttribute($attribute);
    }
}
