<?php

namespace App\Services\Tan90\MasterData;

use App\Models\Tan90\MasterData\DocumentRule;
use App\Models\Tan90\MasterData\MasterAttachment;
use Illuminate\Database\Eloquent\Model;

/**
 * Gates MasterDataController::submit() on tan90_document_rules: if a rule's
 * `entity` field matches this entity's title, every label in its `mandatory`
 * list must have a corresponding tan90_master_attachments row before the
 * record can be submitted for approval. No rule for the entity => nothing
 * enforced (most entities have none seeded).
 */
class DocumentRuleEnforcer
{
    /** @return string[] mandatory labels still missing an attachment (empty = compliant) */
    public function missingDocuments(array $entity, Model $record): array
    {
        $rule = DocumentRule::where('entity', $entity['title'])->where('status', 'active')->first();
        if (! $rule) {
            return [];
        }

        $uploadedLabels = MasterAttachment::where('entity_type', $entity['slug'])
            ->where('entity_id', $record->getKey())
            ->pluck('document_label')
            ->map(fn ($label) => strtolower(trim($label)))
            ->all();

        return collect($rule->mandatoryLabels())
            ->reject(fn ($label) => in_array(strtolower(trim($label)), $uploadedLabels, true))
            ->values()
            ->all();
    }

    public function ruleFor(array $entity): ?DocumentRule
    {
        return DocumentRule::where('entity', $entity['title'])->where('status', 'active')->first();
    }
}
