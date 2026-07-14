<?php

namespace App\Services\Tan90\BomRecipeCosting;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Thin accessor over config('tan90_bom_recipe_costing'). Same shape as the
 * Master Data module's EntityRegistry, scoped to this module's simple
 * reference masters (Recipes/BOMs/Routings/Cost Sheets/ECOs are not in the
 * registry — see the config file's header comment).
 */
class EntityRegistry
{
    public function all(): array
    {
        return config('tan90_bom_recipe_costing.entities', []);
    }

    public function navGroups(): array
    {
        return config('tan90_bom_recipe_costing.nav_groups', []);
    }

    public function has(string $slug): bool
    {
        return isset(config('tan90_bom_recipe_costing.entities', [])[$slug]);
    }

    public function get(string $slug): array
    {
        $entity = config("tan90_bom_recipe_costing.entities.{$slug}");

        if (! $entity) {
            throw new NotFoundHttpException("Unknown BOM/Recipe/Costing entity [{$slug}].");
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
