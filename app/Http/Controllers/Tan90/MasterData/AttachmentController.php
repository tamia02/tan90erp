<?php

namespace App\Http\Controllers\Tan90\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Tan90\MasterData\DocumentRule;
use App\Models\Tan90\MasterData\MasterAttachment;
use App\Services\Tan90\MasterData\AuditLogger;
use App\Services\Tan90\MasterData\EntityRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Uploads/deletes the evidence tan90_document_rules requires before a record
 * can be submitted (see DocumentRuleEnforcer). Files are stored privately
 * (local disk, `tan90-attachments/`) - there is no public download route,
 * only an authorize-gated stream.
 */
class AttachmentController extends Controller
{
    public function __construct(
        private readonly EntityRegistry $registry,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function store(Request $request, string $entity, int $id)
    {
        $config = $this->registry->get($entity);
        $record = $config['model']::findOrFail($id);
        $this->authorize('update', $record);

        $data = $request->validate([
            'document_label' => 'required|string|max:255',
            'file' => 'required|file|max:20480', // 20MB ceiling; DocumentRule.max_size is advisory/display-only
        ]);

        $file = $request->file('file');
        $path = $file->store('tan90-attachments', 'local');

        $rule = DocumentRule::where('entity', $config['title'])->where('status', 'active')->first();

        $attachment = MasterAttachment::create([
            'entity_type' => $entity,
            'entity_id' => $record->id,
            'tan90_document_rule_id' => $rule?->id,
            'document_label' => $data['document_label'],
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        $this->auditLogger->log('ATTACH', $record, "Uploaded '{$data['document_label']}' ({$attachment->original_filename}) to {$record->auditLabel()}.");

        return back()->with('status', "Uploaded {$attachment->original_filename}.");
    }

    public function destroy(Request $request, MasterAttachment $attachment)
    {
        $config = $this->registry->get($attachment->entity_type);
        $record = $config['model']::findOrFail($attachment->entity_id);
        $this->authorize('update', $record);

        Storage::disk('local')->delete($attachment->storage_path);
        $label = $attachment->document_label;
        $attachment->delete();

        $this->auditLogger->log('DETACH', $record, "Removed attachment '{$label}' from {$record->auditLabel()}.");

        return back()->with('status', 'Attachment removed.');
    }
}
