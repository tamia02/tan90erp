<?php

namespace App\Observers;

use App\Models\Tan90\BomRecipeCosting\Bom;
use App\Models\Tan90\BomRecipeCosting\CostSheet;
use App\Models\Tan90\BomRecipeCosting\Recipe;
use App\Services\ZohoService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class BomRecipeCostingObserver
{
    public function saved(Model $record): void
    {
        try {
            $zoho = app(ZohoService::class);

            match (true) {
                $record instanceof Bom => $zoho->pushBom($record),
                $record instanceof Recipe => $zoho->pushRecipe($record),
                $record instanceof CostSheet => $zoho->pushCostSheet($record),
                default => false,
            };
        } catch (\Throwable $exception) {
            Log::warning('Zoho note push failed from BOM/Recipe/Costing observer', [
                'model' => $record::class,
                'id' => $record->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
