<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">{{ $record->{$config['primary']} ?? $config['singular'] }}</h2>
  </x-slot>

  <div class="max-w-4xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
      <div>
        <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted);">{{ $config['title'] }} / Detail</p>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ $record->{$config['code']} ?? '' }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <a href="{{ route('tan90.master-data.index', $entity) }}" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">← Back</a>

        @can('update', $record)
          @if (! empty($config['fields']) && $record->status !== 'archived')
            <a href="{{ route('tan90.master-data.edit', [$entity, $record->id]) }}" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">Edit</a>
          @endif
        @endcan

        @if (empty($config['no_approval']))
          @can('submit', $record)
            @if (in_array($record->approval_status, ['draft', 'rejected']))
              <form method="POST" action="{{ route('tan90.master-data.submit', [$entity, $record->id]) }}">
                @csrf <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">Submit for Approval</button>
              </form>
            @endif
          @endcan
          @can('approve', $record)
            @if (in_array($record->approval_status, ['review', 'pending']))
              <form method="POST" action="{{ route('tan90.master-data.approve', [$entity, $record->id]) }}">
                @csrf <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--status-good);">Approve</button>
              </form>
              <form method="POST" action="{{ route('tan90.master-data.reject', [$entity, $record->id]) }}">
                @csrf <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--status-critical);">Reject</button>
              </form>
            @endif
          @endcan
        @endif

        @if (isset($record->gstin))
          @can('update', $record)
            <form method="POST" action="{{ route('tan90.master-data.verify-gst', [$entity, $record->id]) }}">
              @csrf <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">Verify GST</button>
            </form>
          @endcan
        @endif

        @if ($entity === 'integration-connections')
          @can('settings', $config['model'])
            <form method="POST" action="{{ route('tan90.master-data.integration-connections.test', $record->id) }}">
              @csrf <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">Test Connection</button>
            </form>
          @endcan
        @endif

        @if ($entity === 'data-quality-rules')
          <a href="{{ route('tan90.master-data.data-quality.index') }}" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">View Detected Issues</a>
        @endif

        @if ($record->status === 'archived')
          @can('restore', $record)
            @if (empty($config['no_soft_delete']))
              <form method="POST" action="{{ route('tan90.master-data.restore', [$entity, $record->id]) }}">
                @csrf <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--status-good);">Restore</button>
              </form>
            @endif
          @endcan
        @else
          @can('delete', $record)
            <form method="POST" action="{{ route('tan90.master-data.destroy', [$entity, $record->id]) }}" onsubmit="return confirm('{{ empty($config['no_soft_delete']) ? 'Archive this record?' : 'Delete this record? This cannot be undone.' }}')">
              @csrf @method('DELETE')
              <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--status-critical);">{{ empty($config['no_soft_delete']) ? 'Archive' : 'Delete' }}</button>
            </form>
          @endcan
        @endif
      </div>
    </div>

    @if ($missingDocuments && in_array($record->approval_status, ['draft', 'rejected']))
      <div class="rounded-lg border p-3 mb-4 text-sm" style="background: var(--status-warning-bg); border-color: var(--status-warning); color: var(--status-warning);">
        Submission is blocked until these required documents are uploaded: <strong>{{ implode(', ', $missingDocuments) }}</strong>.
      </div>
    @endif

    @if ($workflowProgress && $workflowProgress->tan90_approval_workflow_id && $workflowProgress->status === 'pending')
      <div class="rounded-lg border p-4 mb-4" style="background: var(--surface-3); border-color: var(--border);">
        <h3 class="font-semibold text-sm mb-1" style="color: var(--text-primary);">{{ $workflowProgress->workflow->name }}</h3>
        <p class="text-xs mb-3" style="color: var(--text-muted);">Multi-step approval in progress</p>
        <div class="flex flex-wrap gap-2 mb-2">
          @foreach ($workflowSteps as $step)
            <span class="text-xs px-2 py-1 rounded" style="{{ $step->step_order === $workflowProgress->current_step_order ? 'background: var(--brand-bg); color: var(--brand);' : 'background: var(--surface-2); color: var(--text-muted);' }}">
              {{ $step->step_order }}. {{ $step->step_role }}{{ $step->step_order < $workflowProgress->current_step_order ? ' ✓' : '' }}
            </span>
          @endforeach
        </div>
        <p class="text-xs" style="color: var(--text-muted);">
          Awaiting approval from the <strong style="color: var(--text-secondary);">{{ $workflowProgress->currentStep()?->step_role ?? 'next' }}</strong> role.
          Any approver may act if that role isn't provisioned in this system yet.
        </p>
      </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex items-center justify-between mb-3">
          <h3 class="font-semibold text-sm" style="color: var(--text-primary);">Record Summary</h3>
          @include('tan90.master-data.partials.status-badge', ['value' => $record->approval_status ?? $record->status])
        </div>
        <div class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
          @foreach ($record->getAttributes() as $key => $value)
            @continue(in_array($key, ['id', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by']))
            @continue(in_array($key, $config['hidden_summary_fields'] ?? []))
            <div>
              <div class="text-xs" style="color: var(--text-muted);">{{ Str::title(str_replace('_', ' ', preg_replace('/_id$/', '', $key))) }}</div>
              <div class="font-medium mt-0.5" style="color: var(--text-primary);">{{ is_bool($value) ? ($value ? 'Yes' : 'No') : ($value ?? '—') }}</div>
            </div>
          @endforeach
        </div>
      </div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h3 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Control Information</h3>
        <div class="flex flex-col gap-3 text-sm">
          <div><div class="text-xs" style="color: var(--text-muted);">Created</div><div style="color: var(--text-primary);">{{ $record->created_at?->format('d M Y, H:i') }}</div></div>
          <div><div class="text-xs" style="color: var(--text-muted);">Last Updated</div><div style="color: var(--text-primary);">{{ $record->updated_at?->format('d M Y, H:i') }}</div><div class="text-xs" style="color: var(--text-muted);">Status: {{ Str::title($record->approval_status ?? $record->status) }}</div></div>
          @if (isset($record->version))
            <div><div class="text-xs" style="color: var(--text-muted);">Version</div><div style="color: var(--text-primary);">v{{ $record->version }}</div></div>
          @endif
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h3 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Change Requests</h3>
        @forelse ($changeRequests as $cr)
          <a href="{{ route('tan90.master-data.change-requests.show', $cr->id) }}" class="flex items-center justify-between gap-3 py-2.5" style="border-top: 1px solid var(--border);">
            <span class="text-sm font-medium" style="color: var(--text-primary);">{{ $cr->request_no }} · {{ implode(', ', array_keys($cr->proposed_changes)) }}</span>
            @include('tan90.master-data.partials.status-badge', ['value' => $cr->approval_status])
          </a>
        @empty
          <p class="text-sm py-4" style="color: var(--text-muted);">No change requests. Critical-field edits will appear here once submitted.</p>
        @endforelse
      </div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h3 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Record Audit</h3>
        @if ($auditTrail->count())
          <div class="flex flex-col divide-y" style="border-color: var(--border);">
            @foreach ($auditTrail as $log)
              <div class="py-2.5">
                <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $log->event }} · {{ $log->user?->name ?? 'System' }}</div>
                <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $log->occurred_at?->format('d M Y, H:i') }} — {{ $log->summary }}</div>
              </div>
            @endforeach
          </div>
        @else
          <p class="text-sm py-4" style="color: var(--text-muted);">No audit events. A new entry will appear on the next change.</p>
        @endif
      </div>
    </div>

    <div class="rounded-lg border p-4 mt-4" style="background: var(--surface-3); border-color: var(--border);">
      <h3 class="font-semibold text-sm mb-1" style="color: var(--text-primary);">Attachments</h3>
      <p class="text-xs mb-3" style="color: var(--text-muted);">{{ $documentRule ? 'Required by ' . $documentRule->name : 'Supporting documents for this record' }}</p>

      @if ($documentRule)
        <div class="inline-flex text-xs px-2 py-1 rounded mb-3" style="background: var(--surface-2); color: var(--text-secondary);">
          Mandatory: <strong class="ml-1" style="color: var(--text-primary);">{{ implode(', ', $documentRule->mandatoryLabels()) ?: 'None' }}</strong>
        </div>
      @endif

      @can('update', $record)
        <form method="POST" action="{{ route('tan90.master-data.attachments.store', [$entity, $record->id]) }}" enctype="multipart/form-data" class="grid grid-cols-1 sm:grid-cols-3 gap-2 mb-4">
          @csrf
          <label class="flex flex-col gap-1 text-xs">
            <span class="font-medium" style="color: var(--text-primary);">Document Label</span>
            @if ($documentRule && $documentRule->mandatoryLabels())
              <select name="document_label" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);" required>
                <option value="">Select document type</option>
                @foreach (array_merge($documentRule->mandatoryLabels(), $documentRule->optionalLabels()) as $label)
                  <option value="{{ $label }}">{{ $label }}</option>
                @endforeach
              </select>
            @else
              <input type="text" name="document_label" placeholder="e.g. GST Certificate" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);" required>
            @endif
          </label>
          <label class="flex flex-col gap-1 text-xs">
            <span class="font-medium" style="color: var(--text-primary);">File</span>
            <input type="file" name="file" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);" required>
          </label>
          <div class="flex items-end">
            <button type="submit" class="rounded-lg px-3 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">Upload</button>
          </div>
        </form>
      @endcan

      @forelse ($attachments as $attachment)
        <div class="flex items-center justify-between py-2" style="border-top: 1px solid var(--border);">
          <div>
            <span class="text-sm font-medium" style="color: var(--text-primary);">{{ $attachment->document_label }}</span>
            <span class="text-xs ml-2" style="color: var(--text-muted);">{{ $attachment->original_filename }} · {{ $attachment->uploader?->name }} · {{ $attachment->created_at->format('d M Y') }}</span>
          </div>
          @can('update', $record)
            <form method="POST" action="{{ route('tan90.master-data.attachments.destroy', $attachment->id) }}" onsubmit="return confirm('Remove this attachment?')">
              @csrf @method('DELETE')
              <button type="submit" class="text-xs font-medium" style="color: var(--status-critical);">Remove</button>
            </form>
          @endcan
        </div>
      @empty
        <p class="text-sm py-4" style="color: var(--text-muted);">No attachments yet.</p>
      @endforelse
    </div>
  </div>
</x-app-layout>
