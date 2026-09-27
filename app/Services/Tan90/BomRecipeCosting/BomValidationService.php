<?php

namespace App\Services\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\BomVersion;

class BomValidationService
{
    public function __construct(private CircularReferenceService $circularReferenceService)
    {
    }

    /** @return array{valid: bool, errors: string[]} */
    public function validate(BomVersion $version): array
    {
        $errors = [];
        $bom = $version->bom;

        if ($version->lines()->count() === 0) {
            $errors[] = 'BOM version has no lines.';
        }

        foreach ($version->lines()->where('quantity', '<=', 0)->get() as $line) {
            $errors[] = "Line #{$line->sequence} has a non-positive quantity.";
        }

        $subBomLines = $version->lines()->where('line_type', 'sub_bom')->whereNotNull('tan90_sub_bom_id')->get();
        foreach ($subBomLines as $line) {
            if ($line->tan90_sub_bom_id === $bom->id) {
                $errors[] = 'A BOM cannot reference itself as a sub-BOM.';
                continue;
            }

            $subBom = $line->subBom;
            if ($subBom && $this->circularReferenceService->wouldCreateCycle($bom, $subBom)) {
                $errors[] = "Sub-BOM '{$subBom->code}' would create a circular reference.";
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }
}
