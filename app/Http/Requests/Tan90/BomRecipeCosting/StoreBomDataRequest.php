<?php

namespace App\Http\Requests\Tan90\BomRecipeCosting;

use App\Services\Tan90\BomRecipeCosting\EntityRegistry;
use App\Services\Tan90\BomRecipeCosting\EntityValidator;
use Illuminate\Foundation\Http\FormRequest;

class StoreBomDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', app(EntityRegistry::class)->modelClass($this->route('entity'))) ?? false;
    }

    public function rules(): array
    {
        $entity = app(EntityRegistry::class)->get($this->route('entity'));

        return app(EntityValidator::class)->rules($entity);
    }
}
