<?php

namespace App\Http\Requests\Tan90\MasterData;

use App\Services\Tan90\MasterData\EntityRegistry;
use App\Services\Tan90\MasterData\EntityValidator;
use App\Services\Tan90\MasterData\NumberSeriesService;
use Illuminate\Foundation\Http\FormRequest;

class StoreMasterDataRequest extends FormRequest
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

    /**
     * Auto-fills a blank natural-key field from a matching tan90_number_series
     * row (module = the entity's title) before validation runs, so a
     * `code`/`sku`/etc. marked required still passes without the user typing one.
     */
    protected function prepareForValidation(): void
    {
        $entity = app(EntityRegistry::class)->get($this->route('entity'));
        $codeField = $entity['code'] ?? null;

        if (! $codeField || $this->filled($codeField)) {
            return;
        }

        $generated = app(NumberSeriesService::class)->next($entity['title']);
        if ($generated) {
            $this->merge([$codeField => $generated]);
        }
    }
}
