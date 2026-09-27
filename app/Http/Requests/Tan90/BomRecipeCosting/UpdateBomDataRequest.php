<?php

namespace App\Http\Requests\Tan90\BomRecipeCosting;

use App\Services\Tan90\BomRecipeCosting\EntityRegistry;
use App\Services\Tan90\BomRecipeCosting\EntityValidator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBomDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        $config = app(EntityRegistry::class)->get($this->route('entity'));
        $record = $config['model']::findOrFail($this->route('id'));

        return $this->user()?->can('update', $record) ?? false;
    }

    public function rules(): array
    {
        $entity = app(EntityRegistry::class)->get($this->route('entity'));

        return app(EntityValidator::class)->rules($entity, (int) $this->route('id'));
    }
}
