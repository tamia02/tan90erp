<?php

namespace App\Http\Requests\Tan90\MasterData;

use App\Services\Tan90\MasterData\EntityRegistry;
use App\Services\Tan90\MasterData\EntityValidator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMasterDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entity = app(EntityRegistry::class)->get($this->route('entity'));
        $record = $entity['model']::findOrFail($this->route('id'));

        return $this->user()?->can('update', $record) ?? false;
    }

    public function rules(): array
    {
        $entity = app(EntityRegistry::class)->get($this->route('entity'));

        return app(EntityValidator::class)->rules($entity, (int) $this->route('id'));
    }
}
